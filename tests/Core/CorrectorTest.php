<?php

use Pecle\Search\Core\Corrector;
use Pecle\Search\Core\EditCandidate;
use Pecle\Search\Core\EditCosts;
use Pecle\Search\Core\EditStage;
use Pecle\Search\Core\Evaluation\TypoGenerator;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\SearchEngine;
use Pecle\Search\Core\TokenResolution;
use Pecle\Search\Core\Trie;
use Pecle\Search\Core\Vocabulary;

/** Vocabulario chico de telas: [palabra, frecuencia]. */
function pecleTextileCorrector(array $options = []): Corrector
{
    $trie = new Trie;
    foreach ([['lana', 90], ['lanas', 20], ['algodon', 80], ['cardada', 30], ['denim', 60], ['azul', 70], ['merino', 25], ['lino', 40]] as $i => [$word, $freq]) {
        $trie->insert($word, $i + 1, $freq);
    }
    $trie->buildTopK(24);
    $normalizer = new Normalizer;

    return new Corrector($trie, new SearchEngine(KeyboardLayout::qwertyEs(), $normalizer), $normalizer, ...$options);
}

it('corrige un error de tecleo y conserva las mayúsculas', function (string $typed, string $expected) {
    $resolution = pecleTextileCorrector()->resolve($typed);

    expect($resolution->applied)->toBe($expected)
        ->and($resolution->original)->toBe($typed)
        ->and($resolution->corrected())->toBeTrue();
})->with([
    ['lqna', 'lana'],
    ['Lqna', 'Lana'],
    ['LQNA', 'LANA'],
    ['algodpn', 'algodon'],
    ['denin', 'denim'],
]);

it('corrige cada palabra y conserva el espaciado', function () {
    $resolution = pecleTextileCorrector()->resolve('  Lqna   cardsda ');

    expect($resolution->applied)->toBe('  Lana   cardada ')
        ->and($resolution->tokens)->toHaveCount(2)
        ->and($resolution->tokens[0]->corrected())->toBeTrue()
        ->and($resolution->tokens[0]->cost)->toBeGreaterThan(0.0);
});

it('no toca lo que ya es válido o se está escribiendo', function (string $typed) {
    $resolution = pecleTextileCorrector()->resolve($typed);

    expect($resolution->applied)->toBe($typed)->and($resolution->corrected())->toBeFalse();
})->with([
    'palabra exacta' => 'lana',
    'palabra con acento' => 'Algodón',
    'prefijo (escribiendo)' => 'lan',
    'prefijo largo' => 'algod',
    'mayúsculas' => 'DENIM',
]);

it('no toca palabras cortas, con dígitos ni vacías', function (string $typed) {
    expect(pecleTextileCorrector()->resolve($typed)->applied)->toBe($typed);
})->with([
    'corta' => 'lq',
    'una letra' => 'q',
    'con dígitos' => 'lqna2',
    'código' => 'TEL-001',
    'medida' => '150cm',
    'vacío' => '',
    'espacios' => '   ',
]);

it('aplica solo el tramo tecleado, sin completar la palabra', function () {
    // "lqn" es un prefijo mal tecleado: se corrige a "lan", que sigue encontrando lana y lanas.
    expect(pecleTextileCorrector()->resolve('lqn')->applied)->toBe('lan');
});

it('mezcla palabras válidas, con error y desconocidas', function () {
    expect(pecleTextileCorrector()->resolve('lana zzzzzz azyl')->applied)->toBe('lana zzzzzz azul');
});

it('rechaza correcciones que superan el tope de costo', function () {
    $corrector = pecleTextileCorrector();

    // tope = max(2, letras): 2 para 3 letras, 4 para 4, 6 para 6...
    expect($corrector->maxCost(1))->toBe(2.0)
        ->and($corrector->maxCost(2))->toBe(2.0)
        ->and($corrector->maxCost(3))->toBe(3.0)
        ->and($corrector->maxCost(6))->toBe(6.0);

    // "zzzzzz" cuesta mucho más de 6 contra cualquier cosa del vocabulario real.
    $golden = json_decode(file_get_contents(__DIR__.'/../Fixtures/layer1-golden.json'), true);
    $trie = new Trie;
    foreach ($golden['vocabulary'] as $i => [$text, $freq]) {
        $trie->insert($text, $i + 1, $freq);
    }
    $trie->buildTopK(24);
    $normalizer = new Normalizer;
    $real = new Corrector($trie, new SearchEngine(KeyboardLayout::qwertyEs(), $normalizer), $normalizer);

    expect($real->resolve('zzzzzz')->applied)->toBe('zzzzzz')
        ->and($real->resolve('Lqna')->applied)->toBe('Lana')
        ->and($real->resolve('wirso')->applied)->toBe('queso');
});

it('el tope se puede ajustar', function () {
    // Con un tope casi nulo, ni un error de una tecla se corrige.
    $strict = pecleTextileCorrector(['costPerChar' => 0.1, 'minCost' => 0.5]);

    expect($strict->resolve('lqna')->applied)->toBe('lqna');
});

/** Corrector con la etapa 2 (una edición) y su atajo, sobre un vocabulario dado. */
function pecleEditCorrector(array $words, bool $shortCircuit = true, bool $prefixLanding = false, bool $edits = true): Corrector
{
    $trie = Vocabulary::fromFrequencies(array_fill_keys($words, 5))->toTrie(24);
    $normalizer = new Normalizer;
    $layout = KeyboardLayout::qwertyEs();

    return new Corrector(
        $trie, new SearchEngine($layout, $normalizer), $normalizer, 3, 1.0, 2.0, 0.0,
        $edits ? new EditStage(new EditCosts($layout)) : null, 4, $shortCircuit ? $layout->minSubstitutionCost() : 0.0, $prefixLanding,
    );
}

describe('corrector with the one-edit stage', function () {
    it('fixes missing, extra and swapped letters and keeps the capitals', function (string $typed, string $expected) {
        $resolution = pecleEditCorrector(['jersey', 'merino', 'amarillo'])->resolve($typed);

        expect($resolution->applied)->toBe($expected)->and($resolution->corrected())->toBeTrue();
    })->with([
        'falta una letra' => ['jrsey', 'jersey'],
        'con mayúscula inicial' => ['Jrsey', 'Jersey'],
        'todo en mayúsculas' => ['JERSYE', 'JERSEY'],
        'letra repetida' => ['jerssey', 'jersey'],
        'letras cruzadas' => ['jersye', 'jersey'],
        'letra doble' => ['amarilo', 'amarillo'],
        'en una frase' => ['jerssey merno', 'jersey merino'],
    ]);

    it('turns a plural into the singular when only the singular exists', function () {
        expect(pecleEditCorrector(['tela', 'lana'])->resolve('telas')->applied)->toBe('tela');
    });

    it('still fixes neighbor-key mistakes, and the cheaper reading wins when both are possible', function () {
        $corrector = pecleEditCorrector(['lana', 'jersey', 'azul']);

        expect($corrector->resolve('Lqna')->applied)->toBe('Lana')
            ->and($corrector->resolve('jwrsey')->applied)->toBe('jersey');
    });

    it('records how each word was fixed', function () {
        $resolution = pecleEditCorrector(['jersey', 'lana'])->resolve('jrsey lqna');

        expect($resolution->tokens[0]->edit)->toBe(EditCandidate::MISSING_LETTER)
            ->and($resolution->tokens[1]->edit)->toBe(TokenResolution::KEYBOARD)
            ->and((new Corrector(
                Vocabulary::fromFrequencies(['lana' => 1])->toTrie(8), new SearchEngine(KeyboardLayout::qwertyEs(), new Normalizer), new Normalizer,
            ))->resolve('lana')->tokens[0]->edit)->toBeNull();
    });

    it('does not touch words that are valid, still being typed, short or with digits', function (string $typed) {
        expect(pecleEditCorrector(['jersey', 'merino'])->resolve($typed)->applied)->toBe($typed);
    })->with(['jersey', 'jerse', 'jrs', 'jer5ey', 'merin']);

    it('gives the same words as without the shortcut', function () {
        $golden = json_decode(file_get_contents(__DIR__.'/../Fixtures/layer1-golden.json'), true);
        $words = array_values(array_filter(array_column($golden['vocabulary'], 0), fn ($w) => preg_match('/^[a-z]{4,}$/', $w)));
        $with = pecleEditCorrector($words, shortCircuit: true);
        $without = pecleEditCorrector($words, shortCircuit: false);
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs(), 4);

        $checked = 0;
        foreach (array_slice($words, 0, 250) as $word) {
            foreach (['substitution', 'omission', 'insertion', 'transposition', 'drift'] as $kind) {
                $typo = $generator->make($kind, $word);
                if ($typo !== null) {
                    expect($with->resolve($typo)->applied)->toBe($without->resolve($typo)->applied, "«{$typo}»");
                    $checked++;
                }
            }
        }

        expect($checked)->toBeGreaterThan(900);
    });

    it('behaves exactly like before when the stage is off', function () {
        $off = pecleEditCorrector(['jersey', 'lana'], edits: false);

        expect($off->resolve('jrsey')->applied)->toBe('jrsey')   // la capa 1 no puede con una omisión
            ->and($off->resolve('Lqna')->applied)->toBe('Lana');
    });

    it('can also land on the start of a word for what is still being typed, when asked', function () {
        expect(pecleEditCorrector(['jersey'])->resolve('jrse')->applied)->toBe('jrse')
            ->and(pecleEditCorrector(['jersey'], prefixLanding: true)->resolve('jrse')->applied)->toBe('jerse');
    });

    describe('a bottom-row letter pressed in place of the space bar', function () {
        $words = ['lana', 'merino', 'algodon', 'grueso', 'azul', 'denim'];

        it('splits the word where the letter is, and the letter becomes the space', function (string $typed, string $expected) use ($words) {
            $resolution = pecleEditCorrector($words)->resolve($typed);

            expect($resolution->applied)->toBe($expected)
                ->and($resolution->corrected())->toBeTrue()
                ->and(array_map(fn (TokenResolution $t) => $t->edit, array_values(array_filter($resolution->tokens, fn (TokenResolution $t) => $t->corrected()))))
                ->toBe([EditCandidate::LETTER_FOR_SPACE]);
        })->with([
            'v' => ['lanavmerino', 'lana merino'],
            'b' => ['lanabmerino', 'lana merino'],
            'c' => ['lanacmerino', 'lana merino'],
            'n' => ['lananmerino', 'lana merino'],
            'x' => ['lanaxmerino', 'lana merino'],
            'm' => ['lanammerino', 'lana merino'],
            'en una frase' => ['denim lanabmerino azul', 'denim lana merino azul'],
            'palabras largas' => ['algodonbgrueso', 'algodon grueso'],
        ]);

        it('keeps the capitals and the accents of what was typed', function (string $typed, string $expected) {
            $resolution = pecleEditCorrector(['lana', 'merino', 'algodon', 'grueso'])->resolve($typed);

            expect($resolution->applied)->toBe($expected);
        })->with([
            'mayúscula inicial' => ['Lanabmerino', 'Lana merino'],
            'cada palabra con la suya' => ['LanabMerino', 'Lana Merino'],
            'todo en mayúsculas' => ['LANABMERINO', 'LANA MERINO'],
            'con acento' => ['AlgodónbGrueso', 'Algodón Grueso'],
        ]);

        it('accepts the start of a word on the right only while that word is still being typed', function () use ($words) {
            $corrector = pecleEditCorrector($words);

            expect($corrector->resolve('lanabmer')->applied)->toBe('lana mer')               // escribiendo
                ->and($corrector->resolve('lanabmer ')->applied)->not->toBe('lana mer ')   // ya terminó: "mer" no es una palabra
                ->and($corrector->resolve('lanabmer azul')->applied)->not->toBe('lana mer azul');
        });

        it('needs a whole word on each side and a letter of the bottom row between them', function (string $typed) {
            $resolution = pecleEditCorrector(['lana', 'merino', 'de', 'cono'])->resolve($typed);

            expect($resolution->tokens[0]->edit)->not->toBe(EditCandidate::LETTER_FOR_SPACE);
        })->with([
            'sin letra en el corte' => 'lanasmerino',
            'otra tecla (la d)' => 'lanadmerino',
            'izquierda demasiado corta' => 'debmerino',
            'derecha demasiado corta (palabra terminada)' => 'lanabde ',
            'la derecha no es una palabra' => 'lanabmerinx',
        ]);

        it('leaves words that already exist or are being typed alone', function (string $typed) {
            $resolution = pecleEditCorrector(['lana', 'merino', 'lanabmerinos'])->resolve($typed);

            expect($resolution->applied)->toBe($typed);
        })->with(['una palabra del vocabulario' => 'lanabmerinos', 'el comienzo de una' => 'lanabmerin']);

        it('does nothing when the one-edit stage is off', function () {
            expect(pecleEditCorrector(['lana', 'merino'], edits: false)->resolve('lanabmerino')->tokens[0]->edit)->not->toBe(EditCandidate::LETTER_FOR_SPACE);
        });

        it('does not split a word with digits or symbols', function (string $typed) {
            expect(pecleEditCorrector(['lana', 'merino'])->resolve($typed)->applied)->toBe($typed);
        })->with(['lana2bmerino', 'lana-bmerino']);

        it('the more frequent reading wins when a word could be cut in two places', function () {
            $trie = Vocabulary::fromFrequencies(['lana' => 50, 'merino' => 40, 'lanam' => 1, 'erino' => 1])->toTrie(24);
            $normalizer = new Normalizer;
            $layout = KeyboardLayout::qwertyEs();
            $corrector = new Corrector($trie, new SearchEngine($layout, $normalizer), $normalizer, 3, 1.0, 2.0, 0.0, new EditStage(new EditCosts($layout)), 4, $layout->minSubstitutionCost());

            expect($corrector->resolve('lanammerino')->applied)->toBe('lana merino');
        });
    });

    describe('Shift or Caps Lock pressed in place of a letter', function () {
        // Teléfono: Shift mayusculiza la letra que sigue ("lNa"). Escritorio: Mayús mayusculiza todo lo que sigue ("lNA").
        it('reads a capital in the middle of the word as the missing letter', function (string $typed, string $expected) {
            $resolution = pecleEditCorrector(['lana', 'lanas', 'luna', 'lazo', 'jersey', 'algodon'])->resolve($typed);

            expect($resolution->applied)->toBe($expected)
                ->and($resolution->tokens[0]->edit)->toBe(EditCandidate::SHIFT_SLIP);
        })->with([
            'teléfono' => ['lNa', 'lana'],
            'escritorio' => ['lNA', 'lana'],
            'teléfono, palabra más larga' => ['lNas', 'lanas'],
            'escritorio, palabra más larga' => ['lNAS', 'lanas'],
            'en lugar de la z' => ['laO', 'lazo'],
        ]);

        it('prefers the A over a more frequent word with another letter, because the capital says which key was missed', function () {
            $layout = KeyboardLayout::qwertyEs();
            $corrector = new Corrector(
                Vocabulary::fromFrequencies(['luna' => 500, 'lana' => 2])->toTrie(24),
                new SearchEngine($layout, new Normalizer), new Normalizer,
                3, 1.0, 2.0, 0.0, new EditStage(new EditCosts($layout)), 4, $layout->minSubstitutionCost(),
            );

            expect($corrector->resolve('lNa')->applied)->toBe('lana')
                ->and($corrector->resolve('lNA')->applied)->toBe('lana');
        });

        it('fixes each word of a phrase on its own', function () {
            expect(pecleEditCorrector(['lana', 'azul'])->resolve('lNa Azul')->applied)->toBe('lana Azul');
        });

        it('does not touch a capital that does not look like the slip', function (string $typed) {
            $resolution = pecleEditCorrector(['lana', 'jersey'])->resolve($typed);

            expect($resolution->applied)->toBe($typed);
        })->with([
            'camel case' => 'iPhoneX',
            'corta' => 'lN',
            'la letra que falta no es una tecla junto a Shift' => 'jNsey',
        ]);

        it('only counts as the slip a single capital followed by lowercase, or capitals up to the end', function (string $typed, bool $slip) {
            $edit = pecleEditCorrector(['lanas', 'lanes'])->resolve($typed)->tokens[0]->edit;

            expect($edit === EditCandidate::SHIFT_SLIP)->toBe($slip);
        })->with([
            'una mayúscula' => ['lNas', true],
            'mayúsculas hasta el final' => ['lNAS', true],
            'mayúscula inicial: lo normal' => ['Lnas', false],
            'todo en mayúsculas' => ['LNAS', false],
            'dos mayúsculas sueltas' => ['lNaS', false],
        ]);

        it('does nothing when the one-edit stage is off', function () {
            $edit = pecleEditCorrector(['lana'], edits: false)->resolve('lNa')->tokens[0]->edit;

            expect($edit)->not->toBe(EditCandidate::SHIFT_SLIP);
        });
    });

    it('does not stop at the first edit it can find: with the golden vocabulary it fixes the classic ones', function () {
        $golden = json_decode(file_get_contents(__DIR__.'/../Fixtures/layer1-golden.json'), true);
        $corrector = pecleEditCorrector(array_column($golden['vocabulary'], 0));

        expect($corrector->resolve('qeso')->applied)->toBe('queso')
            ->and($corrector->resolve('quesoo')->applied)->toBe('queso')
            ->and($corrector->resolve('harnia')->applied)->toBe('harina')
            ->and($corrector->resolve('lehce')->applied)->toBe('leche')
            ->and($corrector->resolve('zzzzzz')->applied)->toBe('zzzzzz');
    });
});
