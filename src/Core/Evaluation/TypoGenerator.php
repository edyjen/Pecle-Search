<?php

namespace Pecle\Search\Core\Evaluation;

use Pecle\Search\Core\KeyboardLayout;

/**
 * Fabrica errores de tecleo sintéticos a partir de palabras reales, para medir
 * el corrector sin esperar a tener errores de verdad. Es determinista (misma
 * semilla, mismos errores) y no toca el generador de números global.
 *
 * Los tres primeros son los que el motor sabe corregir (teclas vecinas y mano
 * corrida); los otros tres, no: sirven para medir qué hace el corrector cuando
 * el error no es de los suyos (lo ideal es que no toque la palabra).
 */
final class TypoGenerator
{
    /** Errores que el motor está diseñado para corregir. */
    public const KEYBOARD_KINDS = ['substitution', 'substitution_2', 'drift'];

    /** Errores que la etapa 2 (una edición) está diseñada para corregir. */
    public const OTHER_KINDS = ['omission', 'insertion', 'transposition'];

    /**
     * Variantes de teclado de los anteriores: letra repetida ("jerssey"), tecla
     * vecina pulsada de más ("jersdey") y falta una de dos letras iguales
     * ("amarilo"). Van al final de las listas para no cambiar los errores que
     * ya producía cada semilla.
     */
    public const EDIT_KINDS = ['repeat', 'neighbor_dup', 'double_omission'];

    /**
     * Errores que mezclan dos palabras: una letra de la fila inferior en lugar del
     * espacio ("lanabmerino" por "lana merino"). Se fabrican con makePair().
     */
    public const PHRASE_KINDS = ['letter_for_space'];

    /** Las teclas de la fila inferior que el pulgar confunde con la barra espaciadora. */
    private const SPACE_KEYS = ['v', 'b', 'c', 'n', 'x', 'm'];

    private int $state;

    public function __construct(
        private readonly KeyboardLayout $layout,
        int $seed = 1,
    ) {
        $this->state = ($seed * 2654435761 + 12345) & 0x7FFFFFFF ?: 1;
    }

    /** Un número entre 0 y $max - 1. */
    public function pick(int $max): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return ($this->state >> 8) % max(1, $max);
    }

    /** El error de ese tipo sobre la palabra, o null si no se pudo producir uno distinto de ella. */
    public function make(string $kind, string $word): ?string
    {
        $chars = mb_str_split($word);
        $length = count($chars);
        if ($length < 3) {
            return null;
        }

        $typo = match ($kind) {
            'substitution' => $this->substitute($chars, 1),
            'substitution_2' => $length >= 4 ? $this->substitute($chars, 2) : null,
            'drift' => $this->drift($chars),
            'omission' => $this->omit($chars),
            'insertion' => $this->insert($chars),
            'transposition' => $this->transpose($chars),
            'repeat' => $this->repeat($chars),
            'neighbor_dup' => $this->neighborDuplicate($chars),
            'double_omission' => $this->omitFromDouble($chars),
            default => null,
        };

        return $typo === null || $typo === $word ? null : $typo;
    }

    /** Las dos palabras pegadas por una letra de la fila inferior (la del espacio que no se pulsó); null si el tipo no es de frase. */
    public function makePair(string $kind, string $first, string $second): ?string
    {
        if ($kind !== 'letter_for_space') {
            return null;
        }

        return $first.self::SPACE_KEYS[$this->pick(count(self::SPACE_KEYS))].$second;
    }

    /** @param list<string> $chars */
    private function substitute(array $chars, int $times): ?string
    {
        $positions = range(0, count($chars) - 1);
        for ($i = 0; $i < $times; $i++) {
            if ($positions === []) {
                return null;
            }
            $at = array_splice($positions, $this->pick(count($positions)), 1)[0];
            $neighbors = $this->letterNeighbors($chars[$at]);
            if ($neighbors === []) {
                return null;
            }
            $chars[$at] = $neighbors[$this->pick(count($neighbors))];
        }

        return implode('', $chars);
    }

    /** Un tramo de 2 a 4 letras corrido una tecla a la derecha (la mano corrida). */
    private function drift(array $chars): ?string
    {
        $length = count($chars);
        $run = min($length, 2 + $this->pick(3));
        $start = $this->pick($length - $run + 1);

        for ($i = $start; $i < $start + $run; $i++) {
            $shifted = $this->layout->shifted($chars[$i], 1);
            if ($shifted === null || ! $this->isLetter($shifted)) {
                return null;
            }
            $chars[$i] = $shifted;
        }

        return implode('', $chars);
    }

    /**
     * Solo teclas de letras: el corrector deja intactas las palabras con dígitos
     * (pueden ser códigos) y la ñ se pliega a n, así que un error con ellas no
     * mide lo que parece.
     *
     * @return list<string>
     */
    private function letterNeighbors(string $char): array
    {
        return array_values(array_filter($this->layout->neighbors($char), fn (string $key) => $this->isLetter($key)));
    }

    private function isLetter(string $key): bool
    {
        return preg_match('/^[a-z]$/', $key) === 1;
    }

    /** @param list<string> $chars */
    private function repeat(array $chars): string
    {
        $at = $this->pick(count($chars));
        array_splice($chars, $at, 0, [$chars[$at]]);

        return implode('', $chars);
    }

    /** Se pulsa también una tecla vecina, antes o después de la letra. */
    private function neighborDuplicate(array $chars): ?string
    {
        $at = $this->pick(count($chars));
        $neighbors = $this->letterNeighbors($chars[$at]);
        if ($neighbors === []) {
            return null;
        }
        array_splice($chars, $at + $this->pick(2), 0, [$neighbors[$this->pick(count($neighbors))]]);

        return implode('', $chars);
    }

    /** Falta una de dos letras iguales seguidas; null si la palabra no tiene letra doble. */
    private function omitFromDouble(array $chars): ?string
    {
        $positions = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            if ($chars[$i] === $chars[$i + 1]) {
                $positions[] = $i;
            }
        }
        if ($positions === []) {
            return null;
        }
        array_splice($chars, $positions[$this->pick(count($positions))], 1);

        return implode('', $chars);
    }

    private function omit(array $chars): string
    {
        array_splice($chars, $this->pick(count($chars)), 1);

        return implode('', $chars);
    }

    private function insert(array $chars): ?string
    {
        $at = $this->pick(count($chars));
        // La letra repetida o una vecina de la que se pulsó.
        $candidates = [$chars[$at], ...$this->letterNeighbors($chars[$at])];
        array_splice($chars, $at, 0, [$candidates[$this->pick(count($candidates))]]);

        return implode('', $chars);
    }

    private function transpose(array $chars): ?string
    {
        $at = $this->pick(count($chars) - 1);
        [$chars[$at], $chars[$at + 1]] = [$chars[$at + 1], $chars[$at]];

        return implode('', $chars);
    }
}
