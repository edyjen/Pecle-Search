<?php

use Pecle\Search\Core\Trie;

it('arma el top-K por nodo ordenado por frecuencia', function () {
    $trie = new Trie;
    $trie->insert('lana', 1, 10);
    $trie->insert('lanas', 2, 50);
    $trie->insert('lancha', 3, 30);
    $trie->insert('lino', 4, 99);
    $trie->buildTopK(2);

    expect(array_map(fn ($c) => $c->text, $trie->exactNode('lan')->topK))->toBe(['lanas', 'lancha'])
        ->and(array_map(fn ($c) => $c->text, $trie->exactNode('l')->topK))->toBe(['lino', 'lanas']);
});

it('distingue palabra completa de prefijo', function () {
    $trie = new Trie;
    $trie->insert('lana', 1, 1);

    expect($trie->hasWord('lana'))->toBeTrue()
        ->and($trie->hasWord('lan'))->toBeFalse()
        ->and($trie->exactNode('lan'))->not->toBeNull()
        ->and($trie->exactNode('lanx'))->toBeNull();
});

it('desempata por orden de inserción', function () {
    $trie = new Trie;
    $trie->insert('azul', 1, 5);
    $trie->insert('aqua', 2, 5);
    $trie->buildTopK(2);

    expect(array_map(fn ($c) => $c->text, $trie->exactNode('a')->topK))->toBe(['azul', 'aqua']);
});

it('maneja palabras con dígitos', function () {
    $trie = new Trie;
    $trie->insert('a1b', 1, 1);
    $trie->buildTopK();

    expect($trie->hasWord('a1b'))->toBeTrue();
});
