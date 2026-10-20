<?php

use Pecle\Search\Core\Contracts\EntitySource;
use Pecle\Search\Core\Contracts\TokenStore;
use Pecle\Search\Core\Indexer;
use Pecle\Search\Core\Normalizer;

/** Índice en memoria: [índice][entidad] = tokens. */
function pecleFakeTokenStore(): TokenStore
{
    return new class implements TokenStore
    {
        public array $data = [];

        public array $built = [];

        public function replace(string $index, string $entityId, array $tokens): void
        {
            $this->data[$index][$entityId] = $tokens;
        }

        public function remove(string $index, string $entityId): void
        {
            unset($this->data[$index][$entityId]);
        }

        public function replaceAll(string $index, iterable $entities): int
        {
            $this->data[$index] = [];
            foreach ($entities as $id => $tokens) {
                $this->data[$index][(string) $id] = $tokens;
            }
            $this->built[$index] = true;

            return count($this->data[$index]);
        }

        public function frequencies(string $index): array
        {
            $freq = [];
            foreach ($this->data[$index] ?? [] as $tokens) {
                foreach ($tokens as $token) {
                    $freq[$token] = ($freq[$token] ?? 0) + 1;
                }
            }

            return $freq;
        }

        public function isBuilt(string $index): bool
        {
            return $this->built[$index] ?? false;
        }

        public function forget(string $index): void
        {
            unset($this->data[$index], $this->built[$index]);
        }
    };
}

function pecleEntities(array $entities): EntitySource
{
    return new class($entities) implements EntitySource
    {
        public function __construct(private array $entities) {}

        public function entities(): iterable
        {
            yield from $this->entities;
        }
    };
}

it('asocia a cada entidad las palabras normalizadas de su texto', function () {
    $store = pecleFakeTokenStore();
    $indexer = new Indexer($store, new Normalizer);

    $count = $indexer->rebuild('fabrics', [pecleEntities(['01A' => 'Algodón Laminado', '01B' => 'Lana Merino'])]);

    expect($count)->toBe(2)
        ->and($store->data['fabrics'])->toBe(['01A' => ['algodon', 'laminado'], '01B' => ['lana', 'merino']])
        ->and($indexer->isBuilt('fabrics'))->toBeTrue();
});

it('une las palabras cuando una entidad viene de varias fuentes y omite las que no tienen palabras', function () {
    $store = pecleFakeTokenStore();
    (new Indexer($store, new Normalizer))->rebuild('colors', [
        pecleEntities(['1' => 'Azul Marino', '2' => '---']),
        pecleEntities(['1' => 'azul 150cm']),
    ]);

    expect($store->data['colors'])->toBe(['1' => ['azul', 'marino', '150cm']]);
});

it('una reconstrucción reemplaza lo anterior', function () {
    $store = pecleFakeTokenStore();
    $indexer = new Indexer($store, new Normalizer);
    $indexer->rebuild('fabrics', [pecleEntities(['1' => 'lana', '2' => 'denim'])]);
    $indexer->rebuild('fabrics', [pecleEntities(['1' => 'lino'])]);

    expect($store->data['fabrics'])->toBe(['1' => ['lino']]);
});

it('reindexa y quita una entidad', function () {
    $store = pecleFakeTokenStore();
    $indexer = new Indexer($store, new Normalizer);
    $indexer->rebuild('fabrics', [pecleEntities(['1' => 'lana', '2' => 'denim'])]);

    $indexer->index('fabrics', '1', 'Lana Cardada');
    expect($store->data['fabrics']['1'])->toBe(['lana', 'cardada']);

    $indexer->index('fabrics', 3, 'Lino'); // id numérico
    expect($store->data['fabrics']['3'])->toBe(['lino']);

    $indexer->index('fabrics', '2', '***'); // sin palabras: equivale a quitarla
    expect($store->data['fabrics'])->not->toHaveKey('2');

    $indexer->remove('fabrics', '1');
    expect($store->data['fabrics'])->not->toHaveKey('1');
});

it('deriva el vocabulario del corrector de lo indexado', function () {
    $indexer = new Indexer(pecleFakeTokenStore(), new Normalizer);
    $indexer->rebuild('fabrics', [pecleEntities([
        '1' => 'Lana Merino 100', '2' => 'Lana Cardada', '3' => 'Algodón TEL-001',
    ])]);

    // frecuencia = nº de entidades; solo palabras de letras
    expect($indexer->vocabulary('fabrics')->words)->toBe([
        ['lana', 2], ['algodon', 1], ['cardada', 1], ['merino', 1], ['tel', 1],
    ]);
});
