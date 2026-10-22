<?php

use Pecle\Search\Core\Corrector;
use Pecle\Search\Core\Evaluation\CorrectorEvaluator;
use Pecle\Search\Core\Evaluation\TypoGenerator;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\SearchEngine;
use Pecle\Search\Core\Vocabulary;

/** Palabras de telas y colores con frecuencias distintas. */
function pecleEvalVocabulary(): Vocabulary
{
    $words = 'algodon poliester franela gabardina terciopelo jersey merino cachemira popelina lanilla sedalina mezclilla '
        .'azul marino turquesa fucsia mostaza vinotinto celeste beige crudo salmon coral lila rosado negro blanco gris '
        .'estampado bordado rayado cuadros liso elastizado peinado cardado laminado satinado';
    $frequencies = [];
    foreach (explode(' ', $words) as $i => $word) {
        $frequencies[$word] = 50 - $i; // frecuencias decrecientes y distintas
    }

    return Vocabulary::fromFrequencies($frequencies);
}

describe('keyboard layout helpers', function () {
    it('lists the keys around a key, without the key itself', function () {
        $layout = KeyboardLayout::qwertyEs();

        expect($layout->neighbors('a'))->toContain('s', 'q', 'z')->not->toContain('a')
            ->and($layout->neighbors('?'))->toBe([]);
    });

    it('finds the key to the right or left in the same row', function () {
        $layout = KeyboardLayout::qwertyEs();

        expect($layout->shifted('q', 1))->toBe('w')
            ->and($layout->shifted('w', -1))->toBe('q')
            ->and($layout->shifted('p', 1))->toBeNull()   // fin de fila
            ->and($layout->shifted('?', 1))->toBeNull();
    });
});

describe('typo generator', function () {
    it('is deterministic for a seed and different across seeds', function () {
        $make = fn (int $seed) => array_map(
            fn (string $word) => (new TypoGenerator(KeyboardLayout::qwertyEs(), $seed))->make('substitution', $word),
            ['algodon', 'franela', 'turquesa', 'gabardina'],
        );

        expect($make(1))->toBe($make(1))->and($make(1))->not->toBe($make(2));
    });

    it('makes each kind of error', function () {
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs(), 7);
        $word = 'terciopelo';

        $changed = fn (string $typo) => count(array_filter(str_split($word), fn ($c, $i) => ($typo[$i] ?? null) !== $c, ARRAY_FILTER_USE_BOTH));

        $substitution = $generator->make('substitution', $word);
        $double = $generator->make('substitution_2', $word);
        $omission = $generator->make('omission', $word);
        $insertion = $generator->make('insertion', $word);
        $transposition = $generator->make('transposition', $word);
        // La mano corrida no puede salirse del teclado (p. ej. la "p" no tiene tecla a su derecha): se reintenta.
        $drift = null;
        for ($attempt = 0; $attempt < 50 && $drift === null; $attempt++) {
            $drift = $generator->make('drift', $word);
        }

        expect(strlen($substitution))->toBe(strlen($word))->and($changed($substitution))->toBe(1)
            ->and(strlen($double))->toBe(strlen($word))->and($changed($double))->toBe(2)
            ->and(strlen($omission))->toBe(strlen($word) - 1)
            ->and(strlen($insertion))->toBe(strlen($word) + 1)
            ->and(strlen($transposition))->toBe(strlen($word))
            ->and(count_chars($transposition, 1))->toBe(count_chars($word, 1))
            ->and(strlen($drift))->toBe(strlen($word))->and($drift)->not->toBe($word);
    });

    it('only uses letters, never digits or the ñ, and never returns the same word', function () {
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs(), 3);

        foreach (['substitution', 'substitution_2', 'drift', 'insertion'] as $kind) {
            for ($i = 0; $i < 200; $i++) {
                $typo = $generator->make($kind, 'elastizado');
                if ($typo !== null) {
                    expect($typo)->toMatch('/^[a-z]+$/')->not->toBe('elastizado');
                }
            }
        }
    });

    it('does not produce errors on words that are too short or unknown kinds', function () {
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs());

        expect($generator->make('substitution', 'ab'))->toBeNull()
            ->and($generator->make('substitution_2', 'abc'))->toBeNull()
            ->and($generator->make('nope', 'algodon'))->toBeNull();
    });
});

describe('corrector evaluator', function () {
    $evaluate = fn (array $configs, int $seed = 1) => (new CorrectorEvaluator(KeyboardLayout::qwertyEs(), new Normalizer))
        ->evaluate(pecleEvalVocabulary(), $configs, sample: 30, seed: $seed);

    it('measures the keyboard errors and the unknown legitimate words', function () use ($evaluate) {
        $report = $evaluate([['costPerChar' => 1.0, 'minCost' => 2.0]]);
        $result = $report['configs'][0];

        expect($report['words'])->toBeGreaterThan(30)
            ->and($report['sample'])->toBe(30)
            ->and($report['holdout'])->toBe(8) // 25 % de la muestra
            ->and($result['kinds'])->toHaveKeys([...TypoGenerator::KEYBOARD_KINDS, ...TypoGenerator::OTHER_KINDS])
            ->and($result['kinds']['substitution']['correct'])->toBeGreaterThan(0)
            ->and($result['holdout']['cases'])->toBe(8);

        foreach ($result['kinds'] as $kind) {
            expect($kind['correct'] + $kind['wrong'])->toBe($kind['corrected'])->and($kind['corrected'])->toBeLessThanOrEqual($kind['cases']);
        }
    });

    it('corrects nothing and changes no legitimate word with a cap of almost zero', function () use ($evaluate) {
        $strict = $evaluate([['costPerChar' => 0.01, 'minCost' => 0.1]])['configs'][0];

        expect(array_sum(array_column($strict['kinds'], 'corrected')))->toBe(0)
            ->and($strict['holdout']['changed'])->toBe(0);
    });

    it('a looser cap corrects at least as many errors as a stricter one', function () use ($evaluate) {
        $configs = $evaluate([
            ['costPerChar' => 0.5, 'minCost' => 2.0],
            ['costPerChar' => 1.5, 'minCost' => 2.0],
        ])['configs'];

        expect(array_sum(array_column($configs[1]['kinds'], 'corrected')))
            ->toBeGreaterThanOrEqual(array_sum(array_column($configs[0]['kinds'], 'corrected')));
    });

    it('gives the same report for the same seed', function () use ($evaluate) {
        $strip = fn (array $report) => array_map(fn ($c) => array_diff_key($c, ['avgMicroseconds' => 1]), $report['configs']);
        $config = [['costPerChar' => 1.0, 'minCost' => 2.0]];

        expect($strip($evaluate($config, 5)))->toBe($strip($evaluate($config, 5)));
    });
});

describe('ambiguity margin', function () {
    function pecleAmbiguousCorrector(float $margin, int $freqD, int $freqG): Corrector
    {
        $trie = Vocabulary::fromFrequencies(['dapo' => $freqD, 'gapo' => $freqG])->toTrie(8);
        $normalizer = new Normalizer;

        // "fapo": la f queda al lado de la d y de la g, a la misma distancia de las dos.
        return new Corrector($trie, new SearchEngine(KeyboardLayout::qwertyEs(), $normalizer), $normalizer, 3, 1.0, 2.0, $margin);
    }

    it('picks one candidate when no margin is asked', function () {
        expect(pecleAmbiguousCorrector(0.0, 5, 5)->resolve('fapo')->corrected())->toBeTrue();
    });

    it('leaves the word alone when two different candidates are about equally good', function () {
        expect(pecleAmbiguousCorrector(1.0, 5, 5)->resolve('fapo')->applied)->toBe('fapo');
    });

    it('corrects when one candidate is clearly better than the other', function () {
        // La frecuencia pesa en el ranking: 500 contra 1 deja un hueco de varios puntos.
        expect(pecleAmbiguousCorrector(1.0, 500, 1)->resolve('fapo')->applied)->toBe('dapo');
    });

    it('does not count a longer version of the same word as a rival', function () {
        $trie = Vocabulary::fromFrequencies(['lana' => 9, 'lanas' => 8])->toTrie(8);
        $normalizer = new Normalizer;
        $corrector = new Corrector($trie, new SearchEngine(KeyboardLayout::qwertyEs(), $normalizer), $normalizer, 3, 1.0, 2.0, 5.0);

        expect($corrector->resolve('lqna')->applied)->toBe('lana');
    });
});

describe('edit kinds in the evaluation', function () {
    it('makes a repeated letter, a neighbor key pressed too and a missing double letter', function () {
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs(), 9);

        $repeat = $generator->make('repeat', 'jersey');
        $neighbor = $generator->make('neighbor_dup', 'jersey');
        $double = $generator->make('double_omission', 'amarillo');

        expect(strlen($repeat))->toBe(7)->and(preg_replace('/(.)\1/', '$1', $repeat))->toBe('jersey')
            ->and(strlen($neighbor))->toBe(7)->and($neighbor)->toMatch('/^[a-z]+$/')
            ->and($double)->toBe('amarilo')
            ->and($generator->make('double_omission', 'jersey'))->toBeNull(); // sin letra doble
    });

    it('does not change the mistakes the older kinds produced for a seed', function () {
        // Los tipos nuevos van al final: la secuencia aleatoria de los viejos no se altera.
        $before = (new TypoGenerator(KeyboardLayout::qwertyEs(), 1));
        $after = (new TypoGenerator(KeyboardLayout::qwertyEs(), 1));
        $after->make('repeat', 'jersey'); // usar un tipo nuevo después no cambia lo ya producido

        expect($before->make('substitution', 'terciopelo'))->toBe((new TypoGenerator(KeyboardLayout::qwertyEs(), 1))->make('substitution', 'terciopelo'));
    });

    it('fixes omissions, extra letters and swaps only with the one-edit stage', function () {
        $evaluator = new CorrectorEvaluator(KeyboardLayout::qwertyEs(), new Normalizer);
        $base = ['costPerChar' => 1.0, 'minCost' => 2.0];

        $report = $evaluator->evaluate(pecleEvalVocabulary(), [$base, $base + ['edits' => true]], sample: 30, seed: 1);
        [$without, $with] = $report['configs'];

        foreach (['omission', 'insertion', 'transposition', 'repeat'] as $kind) {
            // La capa 1 sola ya corrige alguna transposición por casualidad (letras vecinas en el teclado): la etapa 2 corrige muchas más.
            expect($with['kinds'][$kind]['correct'])->toBeGreaterThan($without['kinds'][$kind]['correct']);
        }
        expect($with['edits'])->toBeTrue()->and($without['edits'])->toBeFalse()
            ->and($with['kinds'])->toHaveKeys(TypoGenerator::EDIT_KINDS);

        // Los errores de teclado se siguen corrigiendo con la etapa 2 encendida.
        expect($with['kinds']['substitution']['correct'])->toBeGreaterThan(0);
    });
    it('measures two words glued by a bottom-row letter, which only the one-edit stage can split', function () {
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs(), 5);
        $pair = $generator->makePair('letter_for_space', 'lana', 'merino');

        expect($pair)->toMatch('/^lana[vbcnxm]merino$/')
            ->and($generator->makePair('omission', 'lana', 'merino'))->toBeNull();

        $evaluator = new CorrectorEvaluator(KeyboardLayout::qwertyEs(), new Normalizer);
        $base = ['costPerChar' => 1.0, 'minCost' => 2.0];
        [$without, $with] = $evaluator->evaluate(pecleEvalVocabulary(), [$base, $base + ['edits' => true]], sample: 30, seed: 1)['configs'];

        expect($with['kinds']['letter_for_space']['cases'])->toBeGreaterThan(10)
            ->and($with['kinds']['letter_for_space']['correct'])->toBeGreaterThan($with['kinds']['letter_for_space']['cases'] * 0.8)
            ->and($with['kinds']['letter_for_space']['wrong'])->toBe(0)
            ->and($without['kinds']['letter_for_space']['correct'])->toBe(0);
    });
});
