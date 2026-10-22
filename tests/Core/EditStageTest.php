<?php

use Pecle\Search\Core\EditCandidate;
use Pecle\Search\Core\EditCosts;
use Pecle\Search\Core\EditStage;
use Pecle\Search\Core\Evaluation\TypoGenerator;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Trie;
use Pecle\Search\Core\Vocabulary;

function pecleEditTrie(array $words): Trie
{
    return Vocabulary::fromFrequencies(array_fill_keys($words, 5))->toTrie(8);
}

function pecleEditStage(bool $prefix = false): EditStage
{
    return new EditStage(new EditCosts(KeyboardLayout::qwertyEs()), $prefix);
}

/** @return array<string, EditCandidate> texto => candidato */
function pecleEditCandidates(Trie $trie, string $typed, bool $prefix = false): array
{
    $found = [];
    foreach (pecleEditStage($prefix)->candidates($trie, mb_str_split($typed)) as $candidate) {
        $found[$candidate->text] = $candidate;
    }

    return $found;
}

describe('one-edit stage', function () {
    it('finds the word for each kind of mistake', function (string $typed, string $word, string $kind) {
        $candidates = pecleEditCandidates(pecleEditTrie(['jersey', 'amarillo', 'popelina']), $typed);

        expect($candidates)->toHaveKey($word)->and($candidates[$word]->kind)->toBe($kind);
    })->with([
        'falta una letra' => ['jrsey', 'jersey', EditCandidate::MISSING_LETTER],
        'letra repetida' => ['jerssey', 'jersey', EditCandidate::EXTRA_LETTER],
        'tecla vecina de más' => ['jersdey', 'jersey', EditCandidate::EXTRA_LETTER],
        'letra cualquiera de más' => ['jerspey', 'jersey', EditCandidate::EXTRA_LETTER],
        'letras cruzadas' => ['jersye', 'jersey', EditCandidate::TRANSPOSITION],
        'falta una de dos iguales' => ['amarilo', 'amarillo', EditCandidate::MISSING_LETTER],
        'falta antes del final' => ['popelna', 'popelina', EditCandidate::MISSING_LETTER],
    ]);

    it('costs the likely mistakes less than the generic ones, with a penalty on the first and the last letter', function () {
        $trie = pecleEditTrie(['jersey', 'amarillo', 'apple', 'tela']);
        $cost = fn (string $typed, string $word) => pecleEditCandidates($trie, $typed)[$word]->cost;

        expect($cost('jerssey', 'jersey'))->toBe(1.0)        // repetida
            ->and($cost('jersdey', 'jersey'))->toBe(1.3)     // tecla vecina
            ->and($cost('jerspey', 'jersey'))->toBe(1.8)     // cualquiera
            ->and($cost('amarilo', 'amarillo'))->toBe(1.0)   // letra doble
            ->and($cost('jrsey', 'jersey'))->toBe(1.5)       // falta una
            ->and($cost('jersye', 'jersey'))->toBe(1.3)      // cruzadas
            ->and($cost('mapple', 'apple'))->toBe(2.6)       // 1,8 + 0,8 por tocar la primera letra
            ->and($cost('telas', 'tela'))->toBe(2.1);        // 1,3 (la s es vecina de la a) + 0,8 por borrar la última
    });

    it('cuts a word in two where a bottom-row letter stands in for the space, with the cost of that key', function () {
        $trie = pecleEditTrie(['lana', 'merino', 'azul']);
        $cut = fn (string $typed, bool $open = false) => array_column(array_map(
            fn (EditCandidate $c) => [$c->text, $c],
            pecleEditStage()->letterForSpace($trie, mb_str_split($typed), $open),
        ), 1, 0);

        expect($cut('lanabmerino'))->toHaveKey('lana merino')
            ->and($cut('lanabmerino')['lana merino']->kind)->toBe(EditCandidate::LETTER_FOR_SPACE)
            ->and($cut('lanabmerino')['lana merino']->cost)->toBe(1.0)       // b: sobre la barra
            ->and($cut('lananmerino')['lana merino']->cost)->toBe(1.1)       // n: a un lado
            ->and($cut('lanaxmerino')['lana merino']->cost)->toBe(1.3)       // x: en el extremo
            ->and($cut('lanasmerino'))->toBe([])                              // la s no es de la fila inferior
            ->and($cut('lanabmer'))->toBe([])                                // "mer" no es una palabra
            ->and($cut('lanabmer', open: true))->toHaveKey('lana mer')       // pero se está escribiendo
            ->and($cut('lanabmer', open: true)['lana mer']->isWord)->toBeFalse()
            ->and($cut('lanabm', open: true))->toBe([]);                     // una letra sola no basta
    });

    it('can be turned off, and the halves must have the minimum length', function () {
        $trie = pecleEditTrie(['lana', 'merino', 'de', 'ab']);
        $typed = mb_str_split('lanabmerino');

        expect((new EditStage(new EditCosts(KeyboardLayout::qwertyEs()), false, 1.0, 0.3, splitWords: false))->letterForSpace($trie, $typed, false))->toBe([])
            ->and(pecleEditStage()->letterForSpace($trie, mb_str_split('debmerino'), false))->toBe([])      // "de" tiene 2
            ->and((new EditStage(new EditCosts(KeyboardLayout::qwertyEs()), false, 1.0, 0.3, minHalf: 2))->letterForSpace($trie, mb_str_split('debmerino'), false))->not->toBe([]);
    });

    it('reads the position of a capital as a missing A, Z, Q or S, and nothing else', function () {
        $trie = pecleEditTrie(['lana', 'lano', 'lazo', 'lado', 'lasa']);
        $slip = fn (string $typed, int $at) => array_column(array_map(
            fn (EditCandidate $c) => [$c->text, $c],
            pecleEditStage()->shiftSlip($trie, mb_str_split($typed), $at),
        ), 1, 0);

        $fromTheA = $slip('lno', 1);
        $fromTheZ = $slip('lao', 2);

        expect($fromTheA)->toHaveKey('lano')->and($fromTheA['lano']->kind)->toBe(EditCandidate::SHIFT_SLIP)
            ->and($fromTheA['lano']->cost)->toBe(0.7)
            ->and($fromTheZ)->toHaveKey('lazo')->and($fromTheZ['lazo']->cost)->toBe(0.7)
            ->and($fromTheZ)->not->toHaveKey('lado')            // la d no está junto a Shift ni a Mayús
            ->and($slip('lna', 1))->toHaveKey('lana')
            ->and($slip('laa', 2))->toHaveKey('lasa')           // la s cuesta 1,0 (diagonal)
            ->and($slip('lna', 5))->toBe([])                     // fuera de la palabra
            ->and($slip('lna', 0))->toBe([]);                    // la mayúscula inicial no dice nada
    });

    it('only lands on whole words, so it never completes what was not typed', function () {
        $trie = pecleEditTrie(['jersey']);

        expect(pecleEditCandidates($trie, 'jrse'))->toBe([])      // "jerse" no es una palabra
            ->and(pecleEditCandidates($trie, 'jrsey'))->toHaveKey('jersey');
    });

    it('can also land on the start of a word when asked, for words that are still being typed', function () {
        $candidates = pecleEditCandidates(pecleEditTrie(['jersey']), 'jrse', prefix: true);

        expect($candidates)->toHaveKey('jerse')
            ->and($candidates['jerse']->isWord)->toBeFalse()
            ->and($candidates['jerse']->kind)->toBe(EditCandidate::MISSING_LETTER);
    });

    it('prefers the more frequent word when two are at the same cost', function () {
        $trie = Vocabulary::fromFrequencies(['jersey' => 50, 'jersay' => 1])->toTrie(8);
        $first = pecleEditStage()->candidates($trie, mb_str_split('jrsey'))[0];

        expect($first->text)->toBe('jersey');
    });

    it('returns nothing for words with no edit at distance one', function () {
        expect(pecleEditCandidates(pecleEditTrie(['jersey']), 'zzzzzz'))->toBe([])
            ->and(pecleEditStage()->candidates(pecleEditTrie(['jersey']), ['j']))->toBe([]);
    });

    it('finds the same words as trying every possible edit by brute force', function () {
        // Protege la poda por prefijo: probar solo las posiciones donde lo tecleado aún coincide con
        // el comienzo de una palabra no puede perder ningún candidato.
        $golden = json_decode(file_get_contents(__DIR__.'/../Fixtures/layer1-golden.json'), true);
        $words = array_values(array_filter(array_column($golden['vocabulary'], 0), fn ($w) => preg_match('/^[a-z]{4,}$/', $w)));
        $trie = Vocabulary::fromFrequencies(array_fill_keys($words, 3))->toTrie(8);
        $stage = pecleEditStage();
        $generator = new TypoGenerator(KeyboardLayout::qwertyEs(), 11);

        $compared = 0;
        foreach (array_slice($words, 0, 400) as $word) {
            foreach (['omission', 'insertion', 'transposition', 'repeat'] as $kind) {
                $typed = $generator->make($kind, $word);
                if ($typed === null || $trie->exactNode($typed) !== null) {
                    continue;
                }

                $expected = [];
                $chars = str_split($typed);
                for ($i = 0; $i < count($chars); $i++) {            // borrar una letra
                    $expected[] = implode('', array_merge(array_slice($chars, 0, $i), array_slice($chars, $i + 1)));
                }
                for ($i = 0; $i <= count($chars); $i++) {           // insertar una letra
                    foreach (range('a', 'z') as $letter) {
                        $expected[] = implode('', array_merge(array_slice($chars, 0, $i), [$letter], array_slice($chars, $i)));
                    }
                }
                for ($i = 0; $i + 1 < count($chars); $i++) {        // cruzar dos vecinas
                    $swapped = $chars;
                    [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
                    $expected[] = implode('', $swapped);
                }
                $expected = array_values(array_unique(array_filter($expected, fn (string $text) => $text !== $typed && $trie->hasWord($text))));
                sort($expected);

                $actual = array_map(fn (EditCandidate $c) => $c->text, $stage->candidates($trie, $chars));
                sort($actual);

                expect($actual)->toBe($expected, "«{$typed}» ({$kind} de «{$word}»)");
                $compared++;
            }
        }

        expect($compared)->toBeGreaterThan(300);
    });
});
