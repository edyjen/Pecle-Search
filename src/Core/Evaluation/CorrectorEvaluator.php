<?php

namespace Pecle\Search\Core\Evaluation;

use Pecle\Search\Core\Corrector;
use Pecle\Search\Core\EditCosts;
use Pecle\Search\Core\EditStage;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\SearchEngine;
use Pecle\Search\Core\Vocabulary;

/**
 * Mide el corrector contra un vocabulario real, sin esperar a tener errores de
 * tecleo reales. Para cada configuración de tope de costo responde dos
 * preguntas opuestas, que hay que equilibrar:
 *
 *  - de los errores de tecleo fabricados, ¿cuántos corrige bien, cuántos
 *    "corrige" mal y cuántos deja tal cual?
 *  - de las palabras legítimas que NO están en el índice (se simulan sacando
 *    una parte del vocabulario), ¿cuántas cambia por error? Es el riesgo real
 *    de un corrector: convertir lo que alguien escribió bien en otra cosa.
 *
 * Un error que por casualidad es otra palabra del vocabulario (o el comienzo de
 * una) no cuenta: el corrector tiene que dejarlo tal cual.
 */
final class CorrectorEvaluator
{
    /** Parte de la muestra que se saca del vocabulario para simular palabras legítimas desconocidas. */
    private const HOLDOUT_SHARE = 0.25;

    public function __construct(
        private readonly KeyboardLayout $layout,
        private readonly Normalizer $normalizer,
        private readonly int $topK = 24,
    ) {}

    /**
     * @param  list<array{costPerChar: float, minCost: float, minMargin?: float, edits?: bool, prefixLanding?: bool}>  $configs
     *                                                                                                                           `edits`: con la etapa 2 (una edición) y su atajo; `prefixLanding`: además, aterrizar en el comienzo de una palabra.
     * @return array{
     *     words: int,
     *     sample: int,
     *     holdout: int,
     *     configs: list<array{
     *         costPerChar: float, minCost: float, minMargin: float, edits: bool, prefixLanding: bool,
     *         kinds: array<string, array{cases: int, corrected: int, correct: int, wrong: int}>,
     *         holdout: array{cases: int, changed: int},
     *         avgMicroseconds: float
     *     }>
     * }
     */
    public function evaluate(Vocabulary $vocabulary, array $configs, int $sample = 400, int $seed = 1): array
    {
        $generator = new TypoGenerator($this->layout, $seed);
        $engine = new SearchEngine($this->layout, $this->normalizer);

        $words = $this->evaluableWords($vocabulary);
        $chosen = $this->choose($words, $sample, $generator);
        $holdoutCount = (int) round(count($chosen) * self::HOLDOUT_SHARE);
        $holdout = array_slice($chosen, 0, $holdoutCount);
        $held = array_flip($holdout);

        $fullTrie = $vocabulary->toTrie($this->topK);
        $withoutHeld = (new Vocabulary(array_values(array_filter(
            $vocabulary->words,
            fn (array $entry) => ! isset($held[$entry[0]]),
        ))))->toTrie($this->topK);

        // Los mismos errores para todas las configuraciones.
        $typos = [];
        foreach ([...TypoGenerator::KEYBOARD_KINDS, ...TypoGenerator::OTHER_KINDS, ...TypoGenerator::EDIT_KINDS] as $kind) {
            foreach ($chosen as $word) {
                $typo = $generator->make($kind, $word);
                if ($typo !== null && $fullTrie->exactNode($typo) === null) {
                    $typos[$kind][] = [$word, $typo];
                }
            }
        }

        $results = [];
        foreach ($configs as $config) {
            $margin = $config['minMargin'] ?? 0.0;
            $edits = $config['edits'] ?? false;
            $prefixLanding = $config['prefixLanding'] ?? false;
            $stage = $edits ? new EditStage(new EditCosts($this->layout)) : null;
            $corrector = fn ($trie) => new Corrector(
                $trie, $engine, $this->normalizer, 3, $config['costPerChar'], $config['minCost'], $margin,
                $stage, 4, $edits ? $this->layout->minSubstitutionCost() : 0.0, $prefixLanding,
            );
            $onFull = $corrector($fullTrie);
            $onHeld = $corrector($withoutHeld);

            $started = hrtime(true);
            $queries = 0;
            $kinds = [];

            foreach ($typos as $kind => $cases) {
                $row = ['cases' => 0, 'corrected' => 0, 'correct' => 0, 'wrong' => 0];
                foreach ($cases as [$word, $typo]) {
                    $applied = $onFull->resolve($typo)->applied;
                    $queries++;
                    $row['cases']++;
                    if ($applied !== $typo) {
                        $row['corrected']++;
                        $applied === $word ? $row['correct']++ : $row['wrong']++;
                    }
                }
                $kinds[$kind] = $row;
            }

            $changed = 0;
            foreach ($holdout as $word) {
                $queries++;
                if ($onHeld->resolve($word)->applied !== $word) {
                    $changed++;
                }
            }

            $results[] = [
                'costPerChar' => $config['costPerChar'],
                'minCost' => $config['minCost'],
                'minMargin' => $margin,
                'edits' => $edits,
                'prefixLanding' => $prefixLanding,
                'kinds' => $kinds,
                'holdout' => ['cases' => count($holdout), 'changed' => $changed],
                'avgMicroseconds' => $queries > 0 ? (hrtime(true) - $started) / 1000 / $queries : 0.0,
            ];
        }

        return ['words' => count($words), 'sample' => count($chosen), 'holdout' => count($holdout), 'configs' => $results];
    }

    /**
     * Palabras con las que tiene sentido medir: solo letras y de 4 o más (las
     * cortas no se corrigen).
     *
     * @return list<string>
     */
    private function evaluableWords(Vocabulary $vocabulary): array
    {
        $words = [];
        foreach ($vocabulary->words as [$word]) {
            if (strlen($word) >= 4 && preg_match('/^[a-z]+$/', $word)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function choose(array $words, int $sample, TypoGenerator $generator): array
    {
        // Mezcla determinista (Fisher-Yates con el generador de la semilla).
        for ($i = count($words) - 1; $i > 0; $i--) {
            $j = $generator->pick($i + 1);
            [$words[$i], $words[$j]] = [$words[$j], $words[$i]];
        }

        return array_slice($words, 0, $sample);
    }
}
