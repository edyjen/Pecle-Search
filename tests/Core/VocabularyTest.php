<?php

use Pecle\Search\Core\Vocabulary;

it('arma el vocabulario solo con palabras de letras, ordenadas por frecuencia y luego alfabéticamente', function () {
    $vocabulary = Vocabulary::fromFrequencies([
        'lana' => 5, 'azul' => 5, 'denim' => 9, 'x' => 50, 'tel' => 2,
        '001' => 40, '150cm' => 30, 'a1' => 20, // códigos y medidas: el corrector no los usa
    ]);

    expect($vocabulary->words)->toBe([['denim', 9], ['azul', 5], ['lana', 5], ['tel', 2]])
        ->and($vocabulary->count())->toBe(4);
});

it('tiene la misma huella si tiene el mismo contenido y otra si cambia', function () {
    $a = Vocabulary::fromFrequencies(['lana' => 2, 'denim' => 1]);

    expect($a->version())->toBe(Vocabulary::fromFrequencies(['denim' => 1, 'lana' => 2])->version())
        ->and($a->version())->not->toBe(Vocabulary::fromFrequencies(['lana' => 2, 'denim' => 1, 'lino' => 1])->version())
        ->and($a->version())->not->toBe(Vocabulary::fromFrequencies(['lana' => 3, 'denim' => 1])->version()); // cambia la frecuencia
});

it('va y vuelve por JSON, y rechaza contenido dañado', function () {
    $vocabulary = Vocabulary::fromFrequencies(['lana' => 2, 'merino' => 1]);
    $copy = Vocabulary::fromJson($vocabulary->toJson());

    expect($copy->words)->toBe($vocabulary->words)->and($copy->version())->toBe($vocabulary->version());

    foreach (['', 'no es json', '"texto"', '[["lana"]]', '[["lana","2"]]', '[[1,2]]', '[{"a":1}]'] as $broken) {
        expect(Vocabulary::fromJson($broken))->toBeNull();
    }
});

it('arma un trie utilizable con ids por posición', function () {
    $trie = Vocabulary::fromFrequencies(['lana' => 3, 'lino' => 1])->toTrie(8);

    expect($trie->hasWord('lana'))->toBeTrue()
        ->and($trie->exactNode('lana')->wordId)->toBe(1) // la más frecuente va primero
        ->and($trie->exactNode('l')->topK[0]->text)->toBe('lana');
});
