<?php

use Pecle\Search\Laravel\DatabaseTokenStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Pecle\Search\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true]);
});

function pecleDbStore(int $chunk = 200): DatabaseTokenStore
{
    return new DatabaseTokenStore(DB::connection(), $chunk);
}

function pecleRows(string $index, string $entity): array
{
    return DB::table('search_tokens')->where('index_name', $index)->where('entity_id', $entity)->orderBy('token')->pluck('token')->all();
}

it('reemplaza y quita los tokens de una entidad sin tocar las demás', function () {
    $store = pecleDbStore();
    $store->replace('fabrics', 'A', ['algodon', 'laminado']);
    $store->replace('fabrics', 'B', ['lana']);
    $store->replace('colors', 'A', ['azul']);

    $store->replace('fabrics', 'A', ['algodon', 'peinado']);

    expect(pecleRows('fabrics', 'A'))->toBe(['algodon', 'peinado'])
        ->and(pecleRows('fabrics', 'B'))->toBe(['lana'])
        ->and(pecleRows('colors', 'A'))->toBe(['azul']);

    $store->remove('fabrics', 'A');

    expect(pecleRows('fabrics', 'A'))->toBe([])->and(pecleRows('colors', 'A'))->toBe(['azul']);
});

it('reemplaza todo un índice y lo marca como construido', function () {
    $store = pecleDbStore();
    $store->replace('fabrics', 'viejo', ['viejo']);
    expect($store->isBuilt('fabrics'))->toBeFalse(); // hay filas, pero nunca se construyó

    $count = $store->replaceAll('fabrics', ['A' => ['lana', 'merino'], 'B' => ['lana']]);

    expect($count)->toBe(2)
        ->and($store->isBuilt('fabrics'))->toBeTrue()
        ->and($store->isBuilt('colors'))->toBeFalse()
        ->and(pecleRows('fabrics', 'viejo'))->toBe([])
        ->and(DB::table('search_indexes')->where('index_name', 'fabrics')->value('entities'))->toBe(2);
});

it('reconstruir otra vez actualiza la marca en lugar de duplicarla', function () {
    $store = pecleDbStore();
    $store->replaceAll('fabrics', ['A' => ['lana']]);
    $store->replaceAll('fabrics', ['A' => ['lana'], 'B' => ['lino'], 'C' => ['seda']]);

    expect(DB::table('search_indexes')->count())->toBe(1)
        ->and(DB::table('search_indexes')->value('entities'))->toBe(3);
});

it('reemplaza en bloque de forma atómica: si falla, queda el índice anterior', function () {
    $store = pecleDbStore();
    $store->replaceAll('fabrics', ['A' => ['lana']]);

    $failing = (function () {
        yield 'B' => ['lino'];
        throw new RuntimeException('falló a medio camino');
    })();

    expect(fn () => $store->replaceAll('fabrics', $failing))->toThrow(RuntimeException::class)
        ->and(pecleRows('fabrics', 'A'))->toBe(['lana'])
        ->and(pecleRows('fabrics', 'B'))->toBe([]);
});

it('inserta por tandas sin perder filas', function () {
    $store = pecleDbStore(chunk: 7);
    $entities = [];
    for ($i = 1; $i <= 120; $i++) {
        $entities["E{$i}"] = ["a{$i}", "b{$i}", 'comun'];
    }

    expect($store->replaceAll('big', $entities))->toBe(120)
        ->and(DB::table('search_tokens')->where('index_name', 'big')->count())->toBe(360)
        ->and($store->frequencies('big')['comun'])->toBe(120);
});

it('cuenta en cuántas entidades aparece cada palabra', function () {
    $store = pecleDbStore();
    $store->replaceAll('fabrics', ['A' => ['lana', 'merino', '001'], 'B' => ['lana'], 'C' => ['lino']]);
    $store->replaceAll('colors', ['A' => ['lana']]);

    $freq = $store->frequencies('fabrics');

    expect($freq['lana'])->toBe(2)
        ->and($freq['merino'])->toBe(1)
        ->and($freq[1] ?? $freq['001'] ?? null)->toBe(1) // PHP vuelve int las llaves numéricas
        ->and($store->frequencies('nada'))->toBe([]);
});

it('olvida un índice completo', function () {
    $store = pecleDbStore();
    $store->replaceAll('fabrics', ['A' => ['lana']]);
    $store->replaceAll('colors', ['A' => ['azul']]);
    $store->forget('fabrics');

    expect($store->isBuilt('fabrics'))->toBeFalse()
        ->and(pecleRows('fabrics', 'A'))->toBe([])
        ->and($store->isBuilt('colors'))->toBeTrue();
});

it('no admite la misma palabra dos veces en la misma entidad', function () {
    expect(fn () => pecleDbStore()->replace('fabrics', 'A', ['lana', 'lana']))->toThrow(QueryException::class);
});
