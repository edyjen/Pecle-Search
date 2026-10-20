<?php

use Pecle\Search\Core\Contracts\IndexStore;
use Pecle\Search\Core\IndexRegistry;
use Pecle\Search\Core\Vocabulary;

/** Almacén en memoria que cuenta las lecturas del contenido. */
function pecleFakeStore(): IndexStore
{
    return new class implements IndexStore
    {
        public int $gets = 0;

        public array $data = [];

        public function get(string $name): ?Vocabulary
        {
            $this->gets++;

            return $this->data[$name] ?? null;
        }

        public function put(string $name, Vocabulary $vocabulary): void
        {
            $this->data[$name] = $vocabulary;
        }

        public function forget(string $name): void
        {
            unset($this->data[$name]);
        }

        public function version(string $name): ?string
        {
            return isset($this->data[$name]) ? $this->data[$name]->version() : null;
        }
    };
}

function pecleVocabulary(string ...$words): Vocabulary
{
    return Vocabulary::fromFrequencies(array_fill_keys($words, 1));
}

it('devuelve null mientras no hay vocabulario', function () {
    expect((new IndexRegistry(pecleFakeStore()))->trie('fabrics'))->toBeNull();
});

it('entrega el vocabulario guardado como trie', function () {
    $registry = new IndexRegistry(pecleFakeStore());
    $registry->store('fabrics', pecleVocabulary('algodon', 'marino'));

    expect($registry->trie('fabrics')->hasWord('algodon'))->toBeTrue()
        ->and($registry->trie('fabrics')->hasWord('marino'))->toBeTrue();
});

it('conserva el trie en memoria mientras la versión no cambie', function () {
    $store = pecleFakeStore();
    $registry = new IndexRegistry($store);
    $registry->store('fabrics', pecleVocabulary('lana'));

    $first = $registry->trie('fabrics');
    $registry->trie('fabrics');
    $registry->trie('fabrics');

    expect($registry->trie('fabrics'))->toBe($first)
        ->and($store->gets)->toBe(1);
});

it('recarga cuando otro proceso guarda un vocabulario nuevo', function () {
    $store = pecleFakeStore();
    $worker = new IndexRegistry($store);
    $worker->store('fabrics', pecleVocabulary('lana'));
    $old = $worker->trie('fabrics');

    (new IndexRegistry($store))->store('fabrics', pecleVocabulary('lana', 'denim')); // otro proceso

    $fresh = $worker->trie('fabrics');

    expect($fresh)->not->toBe($old)
        ->and($fresh->hasWord('denim'))->toBeTrue()
        ->and($old->hasWord('denim'))->toBeFalse();
});

it('olvida un índice', function () {
    $registry = new IndexRegistry(pecleFakeStore());
    $registry->store('fabrics', pecleVocabulary('lana'));
    $registry->forget('fabrics');

    expect($registry->trie('fabrics'))->toBeNull();
});

it('mantiene los índices separados por nombre', function () {
    $registry = new IndexRegistry(pecleFakeStore());
    $registry->store('fabrics', pecleVocabulary('lana'));
    $registry->store('colors', pecleVocabulary('azul'));

    expect($registry->trie('fabrics')->hasWord('azul'))->toBeFalse()
        ->and($registry->trie('colors')->hasWord('azul'))->toBeTrue();
});
