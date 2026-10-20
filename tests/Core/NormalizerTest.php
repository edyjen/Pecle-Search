<?php

use Pecle\Search\Core\Normalizer;

it('pliega acentos, ñ y mayúsculas', function (string $input, string $expected) {
    expect((new Normalizer)->fold($input))->toBe($expected);
})->with([
    ['Algodón', 'algodon'],
    ['ALGODÓN', 'algodon'],
    ['Piña', 'pina'],
    ['Pingüino', 'pinguino'],
    ["Pi\u{FFFD}a", 'pina'],
    ["Cafe\u{0301}", 'cafe'], // acento combinante suelto
    ['lana-100%', 'lana-100%'],
    ['', ''],
]);

it('saca las palabras de búsqueda: plegadas, sin repetir y conservando códigos y números', function () {
    $n = new Normalizer;

    expect($n->searchTokens('Algodón Laminado'))->toBe(['algodon', 'laminado'])
        ->and($n->searchTokens('Lana Merino 100% (Importada) lana'))->toBe(['lana', 'merino', '100', 'importada'])
        ->and($n->searchTokens('TEL-001 150cm'))->toBe(['tel', '001', '150cm'])
        ->and($n->searchTokens('001 1 01'))->toBe(['001', '1', '01']) // numéricas distintas: no se confunden
        ->and($n->searchTokens('---'))->toBe([])
        ->and($n->searchTokens(''))->toBe([]);
});

it('acota la cantidad y el largo de las palabras de búsqueda', function () {
    $n = new Normalizer;

    expect($n->searchTokens(implode(' ', range(1, 100))))->toHaveCount(64)
        ->and($n->searchTokens(implode(' ', range(1, 100)), maxTokens: 5))->toHaveCount(5)
        ->and($n->searchTokens(str_repeat('a', 200)))->toBe([str_repeat('a', 64)]);
});
