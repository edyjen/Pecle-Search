<?php

namespace Pecle\Search\Core;

/**
 * Etapa 2 del corrector: palabras del vocabulario a UNA edición de lo tecleado
 * (una letra de más, una de menos o dos cruzadas), para lo que la capa 1 no
 * puede alinear porque cambia el largo.
 *
 * Se apoya en el trie que ya existe, sin estructuras propias. Una edición en
 * la posición i deja intacto lo tecleado antes de i; como la palabra ya se sabe
 * desconocida (no es prefijo de nada), solo tiene sentido editar en las
 * posiciones hasta donde lo tecleado todavía coincide con el comienzo de alguna
 * palabra. Esa poda es exacta (no pierde candidatos) y deja las visitas en unas
 * decenas de nodos, mientras la capa 1 explora cientos.
 *
 * Por defecto solo acepta palabras COMPLETAS: nunca "completa" algo que la
 * persona no escribió. En modo prefijo (para una palabra a medio escribir)
 * también aterriza en el comienzo de una palabra.
 */
final class EditStage
{
    public function __construct(
        private readonly EditCosts $costs,
        /** Aceptar también el comienzo de una palabra (palabra a medio escribir). */
        private readonly bool $prefixLanding = false,
        /** Mismos pesos que el ranking de la capa 1: rank = costo - gamma*ln(1+freq) - completitud. */
        private readonly float $freqGamma = 1.0,
        private readonly float $completenessWeight = 0.3,
        /** Cortar una palabra desconocida en dos cuando una letra de la fila inferior ocupó el lugar del espacio. */
        private readonly bool $splitWords = true,
        /** Largo mínimo de cada mitad si es una palabra completa. */
        private readonly int $minHalf = 3,
        /** Largo mínimo de la mitad derecha si es solo el comienzo de una palabra (la que se está escribiendo). */
        private readonly int $minPrefixHalf = 3,
    ) {}

    /** La misma etapa, pero aterrizando también en el comienzo de una palabra. */
    public function withPrefixLanding(): self
    {
        return new self($this->costs, true, $this->freqGamma, $this->completenessWeight, $this->splitWords, $this->minHalf, $this->minPrefixHalf);
    }

    /**
     * @param  list<string>  $typed  la palabra ya plegada, sabida desconocida
     * @return list<EditCandidate> mejores primero (menor rank)
     */
    public function candidates(Trie $trie, array $typed): array
    {
        $length = count($typed);
        if ($length < 2) {
            return [];
        }

        // Camino por el trie mientras lo tecleado coincide con el comienzo de alguna palabra.
        $path = [$trie->root];
        for ($i = 0; $i < $length; $i++) {
            $next = $path[$i]->children[$typed[$i]] ?? null;
            if ($next === null) {
                break;
            }
            $path[] = $next;
        }

        /** @var array<string, array{0: float, 1: string, 2: TrieNode}> $found texto => [costo, tipo, nodo] */
        $found = [];
        $keep = function (TrieNode $node, string $text, float $cost, string $kind) use (&$found): void {
            if (! isset($found[$text]) || $found[$text][0] > $cost) {
                $found[$text] = [$cost, $kind, $node];
            }
        };

        $last = min(count($path) - 1, $length - 1);
        for ($i = 0; $i <= $last; $i++) {
            $base = $path[$i];

            // Letra de más: se salta $typed[$i] y el resto debe seguir un camino.
            $node = $base;
            for ($j = $i + 1; $j < $length && $node !== null; $j++) {
                $node = $node->children[$typed[$j]] ?? null;
            }
            if ($node !== null && $this->lands($node)) {
                $text = implode('', array_slice($typed, 0, $i)).implode('', array_slice($typed, $i + 1));
                $keep($node, $text, $this->costs->extraLetter($typed, $i), EditCandidate::EXTRA_LETTER);
            }

            // Transposición: $typed[$i] y $typed[$i + 1] cruzadas.
            if ($i + 1 < $length && $typed[$i] !== $typed[$i + 1]) {
                $node = $base->children[$typed[$i + 1]] ?? null;
                $node = $node?->children[$typed[$i]] ?? null;
                for ($j = $i + 2; $j < $length && $node !== null; $j++) {
                    $node = $node->children[$typed[$j]] ?? null;
                }
                if ($node !== null && $this->lands($node)) {
                    $swapped = $typed;
                    [$swapped[$i], $swapped[$i + 1]] = [$typed[$i + 1], $typed[$i]];
                    $keep($node, implode('', $swapped), $this->costs->swappedLetters($i), EditCandidate::TRANSPOSITION);
                }
            }

            // Letra de menos: se prueba cada letra que el trie admite aquí y el resto sigue igual.
            foreach ($base->children as $letter => $child) {
                $node = $child;
                for ($j = $i; $j < $length && $node !== null; $j++) {
                    $node = $node->children[$typed[$j]] ?? null;
                }
                if ($node !== null && $this->lands($node)) {
                    $letter = (string) $letter; // PHP vuelve int las llaves numéricas
                    $text = implode('', array_slice($typed, 0, $i)).$letter.implode('', array_slice($typed, $i));
                    $keep($node, $text, $this->costs->missingLetter($typed, $i, $letter), EditCandidate::MISSING_LETTER);
                }
            }
        }

        $candidates = [];
        foreach ($found as $text => [$cost, $kind, $node]) {
            $candidates[] = $this->candidate((string) $text, $cost, $kind, $node);
        }

        return $this->sorted($candidates);
    }

    /**
     * Palabras a las que se llega si la letra que falta en $at es una de las que tocan
     * Shift y Mayús (a, z, q, s): la persona pulsó la modificadora en vez de la letra y
     * quedó una mayúscula a media palabra ("lNa", "lNA" -> "lana"). Solo mira esa
     * posición — la mayúscula ya dice dónde falta la letra — por eso sirve también en
     * palabras de tres letras, donde una edición cualquiera no tendría evidencia.
     *
     * @param  list<string>  $typed  la palabra ya plegada, sabida desconocida
     * @param  int  $at  dónde falta la letra (la posición de la primera mayúscula)
     * @return list<EditCandidate> mejores primero (menor rank)
     */
    public function shiftSlip(Trie $trie, array $typed, int $at): array
    {
        $length = count($typed);
        $base = $trie->root;
        for ($i = 0; $i < $at && $base !== null; $i++) {
            $base = $base->children[$typed[$i]] ?? null;
        }
        if ($base === null || $at < 1 || $at >= $length) {
            return [];
        }

        $candidates = [];
        foreach ($base->children as $letter => $child) {
            $letter = (string) $letter; // PHP vuelve int las llaves numéricas
            $cost = $this->costs->shiftSlip($letter);
            if ($cost === null) {
                continue;
            }

            $node = $child;
            for ($j = $at; $j < $length && $node !== null; $j++) {
                $node = $node->children[$typed[$j]] ?? null;
            }
            if ($node !== null && $this->lands($node)) {
                $text = implode('', array_slice($typed, 0, $at)).$letter.implode('', array_slice($typed, $at));
                $candidates[] = $this->candidate($text, $cost, EditCandidate::SHIFT_SLIP, $node);
            }
        }

        return $this->sorted($candidates);
    }

    /**
     * Cortes de una palabra desconocida en dos palabras del vocabulario, si la letra del corte es
     * una de la fila inferior que el pulgar confunde con la barra espaciadora ("lanabmerino" ->
     * "lana merino"): la letra sobra y el espacio falta. Es el mismo error del Shift pero al revés
     * de lo que parece: aquí lo que se ve de más es una letra.
     *
     * Evidencia fuerte: la palabra no es palabra ni comienzo de ninguna, y cada mitad sí lo es. La
     * izquierda tiene que ser una palabra completa; la derecha también, salvo que la persona todavía
     * la esté escribiendo ($open), en cuyo caso basta un comienzo. El texto de cada candidato es
     * "izquierda derecha" con la letra del corte ya sustituida por un espacio.
     *
     * @param  list<string>  $typed  la palabra ya plegada (solo a-z), sabida desconocida
     * @param  bool  $open  es la palabra que se está escribiendo (último token, sin espacio detrás)
     * @return list<EditCandidate> mejores primero (menor rank)
     */
    public function letterForSpace(Trie $trie, array $typed, bool $open): array
    {
        $length = count($typed);
        $minRight = $open ? $this->minPrefixHalf : $this->minHalf;
        if (! $this->splitWords || $length < $this->minHalf + 1 + $minRight) {
            return [];
        }

        $candidates = [];
        $left = $trie->root;
        for ($i = 0; $i < $length - 1; $i++) {
            $left = $left->children[$typed[$i]] ?? null;
            if ($left === null) {
                break; // lo tecleado ya no sigue ninguna palabra: ningún corte más a la derecha tiene izquierda válida
            }

            // $left es el nodo de typed[0..i]; el corte sería la letra i + 1.
            $leftLength = $i + 1;
            $cut = $typed[$i + 1] ?? null;
            if ($leftLength < $this->minHalf || ! $left->isWord || $cut === null) {
                continue;
            }
            $cost = $this->costs->spaceKey($cut);
            if ($cost === null) {
                continue;
            }

            $right = $trie->root;
            for ($j = $i + 2; $j < $length && $right !== null; $j++) {
                $right = $right->children[$typed[$j]] ?? null;
            }
            $rightLength = $length - ($i + 2);
            if ($right === null) {
                continue;
            }
            $rightIsWord = $right->isWord && $rightLength >= $this->minHalf;
            $rightIsStart = $open && $rightLength >= $this->minPrefixHalf && ($right->topK ?? []) !== [];
            if (! $rightIsWord && ! $rightIsStart) {
                continue;
            }

            $freqLeft = $left->freq;
            $freqRight = $right->isWord ? $right->freq : ($right->topK[0]->freq ?? 0);
            $text = implode('', array_slice($typed, 0, $leftLength)).' '.implode('', array_slice($typed, $i + 2));
            $rank = $cost - $this->freqGamma * (log1p($freqLeft) + log1p($freqRight)) / 2 - $this->completenessWeight * ($right->isWord ? 1.0 : 0.5);
            $candidates[] = new EditCandidate($text, $cost, min($freqLeft, $freqRight), $rank, EditCandidate::LETTER_FOR_SPACE, $right->isWord);
        }

        return $this->sorted($candidates);
    }

    private function candidate(string $text, float $cost, string $kind, TrieNode $node): EditCandidate
    {
        $isWord = $node->isWord;
        $freq = $isWord ? $node->freq : ($node->topK[0]->freq ?? 0);
        $rank = $cost - $this->freqGamma * log1p($freq) - $this->completenessWeight * ($isWord ? 1.0 : 0.5);

        return new EditCandidate($text, $cost, $freq, $rank, $kind, $isWord);
    }

    /**
     * @param  list<EditCandidate>  $candidates
     * @return list<EditCandidate>
     */
    private function sorted(array $candidates): array
    {
        usort($candidates, fn (EditCandidate $a, EditCandidate $b) => $a->rank <=> $b->rank ?: strcmp($a->text, $b->text));

        return $candidates;
    }

    private function lands(TrieNode $node): bool
    {
        return $node->isWord || ($this->prefixLanding && $node->topK);
    }
}
