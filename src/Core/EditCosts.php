<?php

namespace Pecle\Search\Core;

/**
 * Cuánto cuesta cada tipo de edición de la etapa 2 (una letra de más, una de
 * menos o dos cruzadas). Se comparan con el costo de una sustitución de la capa
 * 1 (alpha × distancia entre teclas: 1,6 la misma fila, hasta 2,88 en el
 * radio), así que los errores mecánicos típicos (letra repetida, dedo gordo,
 * transposición, omisión) cuestan menos que una sustitución y los genéricos,
 * más. La primera letra casi nunca está mal escrita y borrar la última suele
 * ser un plural: ambas llevan recargo.
 *
 * "Letra de más" = la persona tecleó una de sobra (se borra al corregir);
 * "omisión" = le faltó una (se inserta al corregir).
 */
final readonly class EditCosts
{
    public function __construct(
        private KeyboardLayout $layout,
        /** Letra de más que repite la de al lado: "jerssey". */
        public float $duplicate = 1.0,
        /** Letra de más que es vecina en el teclado de la de al lado: "jersdey". */
        public float $fatFinger = 1.3,
        /** Letra de más cualquiera. */
        public float $extra = 1.8,
        /** Falta una de dos letras iguales: "amarilo". */
        public float $doubleOmission = 1.0,
        /** Falta una letra cualquiera: "jrsey". */
        public float $omission = 1.5,
        /** Dos letras vecinas cruzadas: "jersye". */
        public float $transposition = 1.3,
        /** Recargo si la edición toca la primera letra. */
        public float $firstLetterPenalty = 0.8,
        /** Recargo si se borra la última letra y no es repetida (plurales: "telas" -> "tela"). */
        public float $lastLetterPenalty = 0.8,
        /** Mayúscula a media palabra: Shift/Mayús pulsado en lugar de la A o la Z (las teclas que tocan). */
        public float $shiftSlip = 0.7,
        /** Lo mismo, en lugar de la Q o la S (las diagonales de Mayús y Shift). */
        public float $shiftSlipNear = 1.0,
    ) {}

    /**
     * Cuánto cuesta que la letra que falta sea $letter, cuando la palabra trae una
     * mayúscula a media palabra: es la letra que se quiso pulsar y salió Shift o
     * Mayús. Null si la tecla no está junto a ninguna de las dos.
     */
    public function shiftSlip(string $letter): ?float
    {
        return match ($letter) {
            'a', 'z' => $this->shiftSlip,
            'q', 's' => $this->shiftSlipNear,
            default => null,
        };
    }

    /** La persona tecleó $typed[$at] de más. */
    public function extraLetter(array $typed, int $at): float
    {
        $letter = $typed[$at];
        $before = $typed[$at - 1] ?? null;
        $after = $typed[$at + 1] ?? null;

        if ($letter === $before || $letter === $after) {
            $cost = $this->duplicate;
        } elseif (($before !== null && $this->layout->substitutionCost($letter, $before) < INF)
            || ($after !== null && $this->layout->substitutionCost($letter, $after) < INF)) {
            $cost = $this->fatFinger;
        } else {
            $cost = $this->extra;
        }

        $isLast = $at === count($typed) - 1 && $letter !== $before;

        return $cost
            + ($at === 0 ? $this->firstLetterPenalty : 0.0)
            + ($isLast ? $this->lastLetterPenalty : 0.0);
    }

    /** A la persona le faltó $letter antes de $typed[$at]. */
    public function missingLetter(array $typed, int $at, string $letter): float
    {
        $before = $typed[$at - 1] ?? null;
        $after = $typed[$at] ?? null;
        $cost = ($letter === $before || $letter === $after) ? $this->doubleOmission : $this->omission;

        return $cost + ($at === 0 ? $this->firstLetterPenalty : 0.0);
    }

    /** Se cruzaron las letras $at y $at + 1. */
    public function swappedLetters(int $at): float
    {
        return $this->transposition + ($at === 0 ? $this->firstLetterPenalty : 0.0);
    }
}
