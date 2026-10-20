<?php

namespace Pecle\Search\Core;

/**
 * Corrige en silencio los errores de tecleo de un query, palabra por palabra,
 * contra el vocabulario de un índice (Trie).
 *
 * Una palabra se deja tal cual cuando:
 *  - es muy corta (menos de `minLength` caracteres: no hay evidencia);
 *  - contiene dígitos (códigos, medidas);
 *  - ya es una palabra del vocabulario o el comienzo de una — se está escribiendo;
 *  - nada se encuentra dentro del costo máximo aceptable.
 *
 * Dos etapas, que compiten por costo:
 *  1. Capa 1 (SearchEngine): sustituciones por teclas vecinas y mano corrida.
 *     Como no cambia el largo, se aplica el tramo del candidato que cubre lo
 *     tecleado, sin "completar" la palabra: "lqn" pasa a "lan" y la búsqueda
 *     sigue encontrando "lana" y "lanas".
 *  2. Etapa 2 (EditStage, opcional): una letra de más, una de menos o dos
 *     cruzadas ("jrsey", "jerssey", "jersye"). Solo aterriza en palabras
 *     completas y se aplica la palabra entera. Además, si la palabra trae una
 *     mayúscula a media palabra ("lNa", "lNA") se entiende que se pulsó Shift o
 *     Mayús en lugar de la A (o la Z, Q, S) y esa letra es la que falta.
 * Gana la de menor costo; con el mismo costo, la mejor por rank. Atajo exacto:
 * como la capa 1 cuesta al menos `shortCircuitBelow` (una sustitución a la tecla
 * más cercana), si la etapa 2 encuentra algo por menos de eso la capa 1 ni se
 * ejecuta — es la parte cara y el resultado es el mismo.
 *
 * Costo máximo aceptable = max(minCost, costPerChar × letras de la palabra).
 * El motor no tiene tope propio y siempre devuelve algo ("zzzzzz" -> "aderezo",
 * costo 9,8); este tope es lo que separa un error de tecleo de basura.
 */
final class Corrector
{
    public function __construct(
        private readonly Trie $trie,
        private readonly SearchEngine $engine,
        private readonly Normalizer $normalizer,
        private readonly int $minLength = 3,
        private readonly float $costPerChar = 1.0,
        private readonly float $minCost = 2.0,
        /** Ventaja mínima (en puntos de rank) del mejor candidato de la capa 1 sobre otro distinto; 0 = no se exige. */
        private readonly float $minMargin = 0.0,
        /** Etapa 2 (una edición); null = solo la capa 1. */
        private readonly ?EditStage $editStage = null,
        /** La etapa 2 solo se prueba en palabras de al menos este largo. */
        private readonly int $editMinLength = 4,
        /** Costo mínimo de la capa 1; si la etapa 2 cuesta menos, la capa 1 no se ejecuta. 0 = sin atajo. */
        private readonly float $shortCircuitBelow = 0.0,
        /** Si nada más corrige: aterrizar en el comienzo de una palabra (para lo que se está escribiendo). */
        private readonly bool $prefixLanding = false,
    ) {}

    public function resolve(string $query): Resolution
    {
        // Se conserva el espaciado original: los separadores quedan en las posiciones impares.
        $parts = preg_split('/(\s+)/u', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return Resolution::unchanged($query);
        }

        $tokens = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || $part === '') {
                continue;
            }
            $token = $this->resolveWord($part);
            $tokens[] = $token;
            $parts[$i] = $token->applied;
        }

        return new Resolution($query, implode('', $parts), $tokens);
    }

    /** Costo máximo que se acepta para corregir una palabra de $length letras. */
    public function maxCost(int $length): float
    {
        return max($this->minCost, $this->costPerChar * $length);
    }

    private function resolveWord(string $word): TokenResolution
    {
        $folded = $this->normalizer->fold($word);
        $length = mb_strlen($folded);

        if ($length < $this->minLength
            || preg_match('/\d/', $folded)
            || $this->trie->exactNode($folded) !== null) {
            return new TokenResolution($word, $word);
        }

        $limit = $this->maxCost($length);
        $chars = mb_str_split($folded);

        $edit = null;
        if ($this->editStage !== null) {
            if ($length >= $this->editMinLength) {
                $edit = $this->bestEdit($this->editStage, $chars, $limit);
            }

            // Shift o Mayús pulsado en lugar de una letra: la mayúscula a media palabra es la evidencia,
            // así que vale también para palabras demasiado cortas para la etapa 2.
            $slipAt = $this->shiftSlipAt($word);
            if ($slipAt !== null) {
                foreach ($this->editStage->shiftSlip($this->trie, $chars, $slipAt) as $slip) {
                    if ($slip->cost <= $limit) {
                        if ($edit === null || $slip->cost < $edit->cost || (abs($slip->cost - $edit->cost) < 1e-9 && $slip->rank < $edit->rank)) {
                            $edit = $slip;
                        }
                        break;
                    }
                }
            }

            // Atajo: la capa 1 no puede ser más barata que una sustitución a la tecla más cercana.
            if ($edit !== null && $this->shortCircuitBelow > 0.0 && $edit->cost < $this->shortCircuitBelow) {
                return $this->fromEdit($word, $edit);
            }
        }

        $hit = $this->layerOne($folded, $length, $limit);

        if ($edit !== null && ($hit === null || $edit->cost < $hit->cost || (abs($edit->cost - $hit->cost) < 1e-9 && $edit->rank < $hit->rank))) {
            return $this->fromEdit($word, $edit);
        }

        if ($hit !== null) {
            return new TokenResolution($word, $this->matchCase($word, mb_substr($hit->text, 0, $length)), $hit->cost, TokenResolution::KEYBOARD);
        }

        // Nada corrige: puede ser una palabra a medio escribir con un error (falta una letra antes del final).
        if ($this->prefixLanding && $this->editStage !== null && $length >= $this->editMinLength) {
            $prefix = $this->bestEdit($this->editStage->withPrefixLanding(), $chars, $limit);
            if ($prefix !== null) {
                return $this->fromEdit($word, $prefix);
            }
        }

        return new TokenResolution($word, $word);
    }

    /**
     * El mejor candidato de la capa 1 dentro del tope, o null (sin candidato o
     * demasiado ambiguo si se pidió un margen).
     */
    private function layerOne(string $folded, int $length, float $limit): ?Hit
    {
        $best = null;
        foreach ($this->engine->search($this->trie, $folded) as $hit) {
            if ($hit->cost > $limit) {
                continue;
            }

            if ($best === null) {
                $best = $hit;
                if ($this->minMargin <= 0.0) {
                    break;
                }

                continue;
            }

            // La misma palabra en versión más larga no es un rival: se sigue buscando uno distinto.
            if (mb_substr($hit->text, 0, $length) === mb_substr($best->text, 0, $length)) {
                continue;
            }

            // Otro candidato distinto casi tan bueno: la corrección es ambigua, mejor no tocar nada.
            if ($hit->rank - $best->rank < $this->minMargin) {
                return null;
            }

            break;
        }

        return $best;
    }

    /** @param list<string> $chars */
    private function bestEdit(EditStage $stage, array $chars, float $limit): ?EditCandidate
    {
        foreach ($stage->candidates($this->trie, $chars) as $candidate) {
            if ($candidate->cost <= $limit) {
                return $candidate;
            }
        }

        return null;
    }

    private function fromEdit(string $word, EditCandidate $edit): TokenResolution
    {
        return new TokenResolution(
            $word,
            $this->matchCase($word, $edit->text),
            $edit->cost,
            $edit->isWord ? $edit->kind : TokenResolution::PREFIX,
        );
    }

    /**
     * Dónde (en la palabra ya plegada) falta una letra, si lo escrito delata un Shift o
     * un Mayús pulsado por error en lugar de ella; null si no hay tal rastro.
     *
     * Shift (teléfono) pone en mayúscula solo la letra siguiente ("lNa") y Mayús (escritorio)
     * todas las que siguen ("lNA"); en ambos la letra que se quiso pulsar no aparece. Se pide una
     * minúscula seguida de una mayúscula y que el resto sea esa única mayúscula con minúsculas,
     * o mayúsculas hasta el final: "McDonald" o "iPhoneX" no son este error.
     * Una mayúscula a comienzo de palabra es lo normal y no dice nada.
     */
    private function shiftSlipAt(string $word): ?int
    {
        if (preg_match('/^(?<before>\p{Lu}?\p{Ll}+)(?:\p{Lu}\p{Ll}*|\p{Lu}+)$/u', $word, $match) !== 1) {
            return null;
        }

        return mb_strlen($this->normalizer->fold($match['before']));
    }

    /** Conserva las mayúsculas de lo escrito: "Lqna" -> "Lana", "LQNA" -> "LANA". */
    private function matchCase(string $typed, string $corrected): string
    {
        if (mb_strlen($typed) > 1 && $typed === mb_strtoupper($typed, 'UTF-8')) {
            return mb_strtoupper($corrected, 'UTF-8');
        }
        $first = mb_substr($typed, 0, 1);
        if ($first !== mb_strtolower($first, 'UTF-8')) {
            return mb_strtoupper(mb_substr($corrected, 0, 1), 'UTF-8').mb_substr($corrected, 1);
        }

        return $corrected;
    }
}
