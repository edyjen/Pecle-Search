<?php

use Pecle\Search\Core\IndexRegistry;
use Pecle\Search\Core\Vocabulary;
use Pecle\Search\Laravel\CacheIndexStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function pecleCacheStore(?Repository $cache = null): CacheIndexStore
{
    return new CacheIndexStore($cache ?? new Repository(new ArrayStore));
}

it('guarda y recupera un vocabulario con su versión', function () {
    $store = pecleCacheStore();
    $vocabulary = new Vocabulary([['lana', 3], ['denim', 1]]);

    expect($store->get('fabrics'))->toBeNull()->and($store->version('fabrics'))->toBeNull();

    $store->put('fabrics', $vocabulary);

    expect($store->get('fabrics')->words)->toBe($vocabulary->words)
        ->and($store->version('fabrics'))->toBe($vocabulary->version());
});

it('mantiene la versión sincronizada con el contenido al reemplazar', function () {
    $store = pecleCacheStore();
    $store->put('fabrics', new Vocabulary([['lana', 1]]));
    $second = new Vocabulary([['lana', 1], ['denim', 1]]);
    $store->put('fabrics', $second);

    expect($store->version('fabrics'))->toBe($second->version())
        ->and($store->get('fabrics')->version())->toBe($second->version());
});

it('olvida un índice por nombre sin tocar los demás', function () {
    $store = pecleCacheStore();
    $store->put('fabrics', new Vocabulary([['lana', 1]]));
    $store->put('colors', new Vocabulary([['azul', 1]]));
    $store->forget('fabrics');

    expect($store->get('fabrics'))->toBeNull()
        ->and($store->version('fabrics'))->toBeNull()
        ->and($store->get('colors'))->not->toBeNull();
});

it('trata una caché dañada como índice ausente', function () {
    $cache = new Repository(new ArrayStore);
    $cache->forever('pecle_search:fabrics:words', '{no es un vocabulario');

    expect(pecleCacheStore($cache)->get('fabrics'))->toBeNull();
});

it('se separa por prefijo', function () {
    $cache = new Repository(new ArrayStore);
    (new CacheIndexStore($cache, 'a'))->put('fabrics', new Vocabulary([['lana', 1]]));

    expect((new CacheIndexStore($cache, 'b'))->get('fabrics'))->toBeNull();
});

it('funciona de punta a punta con el registro', function () {
    $store = pecleCacheStore();
    (new IndexRegistry($store))->store('fabrics', Vocabulary::fromFrequencies(['lana' => 2, 'cardada' => 1]));

    // Otro proceso: registro nuevo sobre la misma caché.
    $other = new IndexRegistry($store);

    expect($other->trie('fabrics')->hasWord('cardada'))->toBeTrue();
});
