<?php

use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\SearchEngine;
use Pecle\Search\Core\Trie;

/*
 * Paridad con layer1Search.js: Fixtures/layer1-golden.json es la salida del
 * motor original sobre un vocabulario y unos queries fijos. Si estas pruebas
 * fallan, el port se desvió del algoritmo diseñado.
 */

function pecleGolden(): array
{
    static $golden = null;

    return $golden ??= json_decode(file_get_contents(__DIR__.'/../Fixtures/layer1-golden.json'), true, flags: JSON_THROW_ON_ERROR);
}

function pecleGoldenTrie(): Trie
{
    static $trie = null;
    if ($trie === null) {
        $golden = pecleGolden();
        $trie = new Trie;
        foreach ($golden['vocabulary'] as $i => [$text, $freq]) {
            $trie->insert($text, $i + 1, $freq);
        }
        $trie->buildTopK($golden['meta']['topK']);
    }

    return $trie;
}

function pecleGoldenEngine(): SearchEngine
{
    $d = pecleGolden()['meta']['defaults'];

    return new SearchEngine(KeyboardLayout::qwertyEs(), new Normalizer, $d['narrowDepth'], $d['beamWidth'], $d['maxResults']);
}

/**
 * Compara el motor con la referencia en todos los casos de una configuración y
 * devuelve las diferencias (vacío = paridad total). Un solo test por
 * configuración, en lugar de uno por caso, para no multiplicar la memoria de
 * la suite.
 */
function pecleParityMismatches(string $opts): array
{
    $engine = pecleGoldenEngine();
    $trie = pecleGoldenTrie();
    $mismatches = [];

    foreach (pecleGolden()['cases'] as $case) {
        if ($case['opts'] !== $opts) {
            continue;
        }

        $actual = $engine->search(
            $trie,
            $case['query'],
            $case['options']['narrowDepth'] ?? null,
            $case['options']['beamWidth'] ?? null,
            $case['options']['maxResults'] ?? null,
        );

        $label = sprintf('%s [%s]', json_encode($case['query'], JSON_UNESCAPED_UNICODE), $case['note']);
        $expectedTexts = array_column($case['results'], 'text');

        if (array_map(fn ($h) => $h->text, $actual) !== $expectedTexts) {
            $mismatches[] = "$label: esperado ".implode(',', $expectedTexts).' / obtenido '.implode(',', array_map(fn ($h) => $h->text, $actual));

            continue;
        }

        foreach ($case['results'] as $i => $expected) {
            $hit = $actual[$i];
            if ($hit->wordId !== $expected['wordId'] || $hit->freq !== $expected['freq']
                || abs($hit->cost - $expected['cost']) > 1e-9 || abs($hit->rank - $expected['rank']) > 1e-9) {
                $mismatches[] = "$label: {$expected['text']} difiere en id/frecuencia/costo/rank";
            }
        }
    }

    return $mismatches;
}

it('reproduce la salida de layer1Search.js con la configuración normal', function () {
    expect(pecleParityMismatches('default'))->toBe([]);
});

it('reproduce la salida de layer1Search.js con haz estrecho', function () {
    expect(pecleParityMismatches('estrecho'))->toBe([]);
});

it('reproduce la salida de layer1Search.js limitando a 3 resultados', function () {
    expect(pecleParityMismatches('top3'))->toBe([]);
});

it('cubre los casos esperados de la referencia', function () {
    expect(pecleGolden()['cases'])->toHaveCount(pecleGolden()['meta']['cases']);

    $engine = pecleGoldenEngine();
    $trie = pecleGoldenTrie();

    expect($engine->search($trie, 'Lqna')[0]->text)->toBe('lana') // sustitución adyacente
        ->and($engine->search($trie, 'wirso')[0]->text)->toBe('queso') // mano corrida
        ->and($engine->search($trie, 'Algodón')[0]->text)->toBe('algodon') // normalización
        ->and($engine->search($trie, ''))->toBe([])
        ->and($engine->search($trie, 'qeso')[0]->text)->not->toBe('queso'); // omisión: fuera de alcance de layer 1
});
