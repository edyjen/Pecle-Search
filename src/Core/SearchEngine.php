<?php

namespace Pecle\Search\Core;

/**
 * Motor de corrección (capa 1): corrige SOLO desplazamiento de teclado — sustitución por teclas
 * vecinas y "mano corrida" (deriva vectorial). No modela omisión, inserción
 * ni transposición, así que cada carácter del query consume exactamente un
 * nivel del trie.
 *
 * No tiene tope de costo: se rankea con lo que sobreviva al final. El tamaño
 * de la búsqueda se controla con un haz (beam) sin recorte en los primeros
 * `narrowDepth` caracteres y de `beamWidth` estados después. Quien consuma los
 * resultados decide qué costo es aceptable.
 *
 * Deriva: cada rama lleva el vector de error del último carácter errado. Si el
 * siguiente error tiene el mismo vector, es la misma mano corrida y cuesta lo
 * que cambió el vector (0 si es idéntico); sin deriva previa, la sustitución
 * se limita al radio del teclado.
 *
 * Port de layer1Search.js (ClaudeAutocomplete); la paridad se verifica contra
 * Tests/Fixtures/layer1-golden.json.
 */
final class SearchEngine
{
    public function __construct(
        private readonly KeyboardLayout $layout,
        private readonly Normalizer $normalizer,
        private readonly int $narrowDepth = 4,
        private readonly int $beamWidth = 60,
        private readonly int $maxResults = 8,
        /** Peso de la frecuencia en el ranking final: rank = cost - gamma*ln(1+freq) - ... */
        private readonly float $freqGamma = 1.0,
        /** Desempate a favor de la coincidencia exacta frente a una continuación de prefijo. */
        private readonly float $completenessWeight = 0.3,
    ) {}

    /** @return list<Hit> mejores primero (menor rank) */
    public function search(Trie $trie, string $query, ?int $narrowDepth = null, ?int $beamWidth = null, ?int $maxResults = null): array
    {
        $narrowDepth ??= $this->narrowDepth;
        $beamWidth ??= $this->beamWidth;
        $maxResults ??= $this->maxResults;

        $chars = mb_str_split($this->normalizer->fold($query));
        $length = count($chars);
        if ($length === 0) {
            return [];
        }

        $frontier = [$this->key($trie->root, null) => new FrontierState($trie->root, null, 0.0)];

        for ($i = 0; $i < $length && $frontier; $i++) {
            $typed = $chars[$i];
            $next = [];
            $relax = function (TrieNode $node, ?array $drift, float $cost) use (&$next): void {
                $key = $this->key($node, $drift);
                if (! isset($next[$key]) || $next[$key]->cost > $cost) {
                    $next[$key] = new FrontierState($node, $drift, $cost);
                }
            };

            foreach ($frontier as $state) {
                foreach ($state->node->children as $char => $child) {
                    $char = (string) $char; // PHP vuelve int las llaves numéricas
                    if ($char === $typed) {
                        $relax($child, null, $state->cost); // acierto: sin error, no hay deriva que cargar

                        continue;
                    }

                    if ($state->drift === null) {
                        // Origen de una posible rama nueva: acotado por el radio del teclado.
                        $cost = $this->layout->substitutionCost($typed, $char);
                        if ($cost === INF) {
                            continue;
                        }
                        $relax($child, $this->layout->errorVector($typed, $char), $state->cost + $cost);

                        continue;
                    }

                    // Deriva establecida: se paga solo lo que cambió el vector. Sin tope.
                    $error = $this->layout->errorVector($typed, $char);
                    if ($error === null) {
                        continue; // carácter fuera del teclado
                    }
                    $delta = $this->layout->alpha() * hypot($error['dx'] - $state->drift['dx'], $error['dy'] - $state->drift['dy']);
                    $relax($child, $error, $state->cost + $delta);
                }
            }

            // Sin recorte hasta narrowDepth; después, solo los beamWidth estados más baratos.
            if ($i + 1 > $narrowDepth && count($next) > $beamWidth) {
                $states = array_values($next);
                usort($states, fn (FrontierState $a, FrontierState $b) => $a->cost <=> $b->cost);
                $next = [];
                foreach (array_slice($states, 0, $beamWidth) as $state) {
                    $next[$this->key($state->node, $state->drift)] = $state;
                }
            }
            $frontier = $next;
        }

        if ($frontier === []) {
            return []; // ninguna rama sobrevivió al radio del teclado
        }

        /** @var array<int, Hit> $results */
        $results = [];
        foreach ($frontier as $state) {
            foreach ($state->node->topK ?? [] as $candidate) {
                // Sin indel, el nodo está a profundidad $length: si el texto mide eso, ES la palabra del nodo.
                $completeness = mb_strlen($candidate->text) === $length ? 1.0 : 0.5;
                $rank = $state->cost - $this->freqGamma * log1p($candidate->freq) - $this->completenessWeight * $completeness;
                if (! isset($results[$candidate->wordId]) || $results[$candidate->wordId]->rank > $rank) {
                    $results[$candidate->wordId] = new Hit($candidate->wordId, $candidate->text, $state->cost, $candidate->freq, $rank);
                }
            }
        }

        $ranked = array_values($results);
        usort($ranked, fn (Hit $a, Hit $b) => $a->rank <=> $b->rank);

        // Poda de prefijos: una palabra que es prefijo de otra mejor rankeada sobra.
        $out = [];
        foreach ($ranked as $hit) {
            foreach ($out as $kept) {
                if (mb_strlen($kept->text) > mb_strlen($hit->text) && str_starts_with($kept->text, $hit->text)) {
                    continue 2;
                }
            }
            $out[] = $hit;
            if (count($out) === $maxResults) {
                break;
            }
        }

        return $out;
    }

    /** @param array{dx: float, dy: float}|null $drift */
    private function key(TrieNode $node, ?array $drift): string
    {
        return spl_object_id($node).'|'.($drift === null ? '0' : $drift['dx'].'|'.$drift['dy']);
    }
}
