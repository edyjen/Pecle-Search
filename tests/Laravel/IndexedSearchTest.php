<?php

use Pecle\Search\Laravel\PecleSearchManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pecle\Search\Tests\TestCase;

uses(TestCase::class);

class PecleSearchableFabric extends Model
{
    protected $table = 'pecle_fabrics';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true]);

    Schema::create('pecle_fabrics', function (Blueprint $table) {
        $table->string('id', 26)->primary();
        $table->string('name');
        $table->string('code')->nullable();
    });

    foreach ([
        ['F1', 'Algodón Laminado', 'TEL-001'],
        ['F2', 'Algodón Peinado', 'TEL-002'],
        ['F3', 'Lana Merino', 'TEL-010'],
        ['F4', 'Gabardina', null],
    ] as [$id, $name, $code]) {
        PecleSearchableFabric::create(['id' => $id, 'name' => $name, 'code' => $code]);
    }

    config(['pecle_search.indexes.fabrics' => [['model' => PecleSearchableFabric::class, 'columns' => ['name', 'code']]]]);
});

function pecleFound(string $term, string $index = 'fabrics'): array
{
    return PecleSearchableFabric::query()->whereIndexed($index, $term, ['name', 'code'])->orderBy('id')->pluck('id')->all();
}

it('encuentra por el comienzo de cualquier palabra, sin importar acentos ni mayúsculas', function (string $term, array $expected) {
    app(PecleSearchManager::class)->rebuild('fabrics');

    expect(pecleFound($term))->toBe($expected);
})->with([
    'palabra completa sin acento' => ['algodon', ['F1', 'F2']],
    'palabra completa con acento' => ['Algodón', ['F1', 'F2']],
    'mayúsculas' => ['ALGODÓN', ['F1', 'F2']],
    'comienzo de palabra' => ['lam', ['F1']],
    'palabra del medio del nombre' => ['merino', ['F3']],
    'varias palabras (todas deben cumplirse)' => ['algod lam', ['F1']],
    'orden indistinto' => ['laminado algodon', ['F1']],
    'una palabra que no existe' => ['algodon seda', []],
    'código entero' => ['TEL-001', ['F1']],
    'comienzo de código' => ['tel-0', ['F1', 'F2', 'F3']],
    'mitad de palabra no encuentra' => ['odon', []],
    'sin palabras: ningún resultado' => ['---', []],
]);

it('cada índice es independiente', function () {
    $manager = app(PecleSearchManager::class);
    $manager->rebuild('fabrics');
    config(['pecle_search.indexes.otro' => [['model' => PecleSearchableFabric::class, 'columns' => ['code']]]]);
    $manager->rebuild('otro');

    expect(pecleFound('algodon', 'otro'))->toBe([])      // 'otro' solo conoce los códigos
        ->and(pecleFound('tel-001', 'otro'))->toBe(['F1'])
        ->and(pecleFound('algodon'))->toBe(['F1', 'F2']);
});

it('combina con otras condiciones de la consulta', function () {
    app(PecleSearchManager::class)->rebuild('fabrics');

    $ids = PecleSearchableFabric::query()->where('code', 'TEL-002')->whereIndexed('fabrics', 'algodon')->pluck('id')->all();

    expect($ids)->toBe(['F2']);
});

it('mientras el índice no esté construido busca con LIKE en las columnas de respaldo', function () {
    expect(pecleFound('merino'))->toBe(['F3'])           // LIKE %merino% sobre name/code
        ->and(pecleFound('mer'))->toBe(['F3'])
        ->and(pecleFound('lgod'))->toBe(['F1', 'F2'])    // y aquí sí: mitad de palabra, como antes
        ->and(pecleFound('zzz'))->toBe([]);

    app(PecleSearchManager::class)->rebuild('fabrics');

    expect(pecleFound('lgod'))->toBe([]); // ya busca contra el índice
});

it('sin columnas de respaldo y sin índice no filtra', function () {
    expect(PecleSearchableFabric::query()->whereIndexed('fabrics', 'merino')->count())->toBe(4);
});

it('refleja los cambios por entidad sin reconstruir todo', function () {
    $manager = app(PecleSearchManager::class);
    $manager->rebuild('fabrics');

    $fabric = PecleSearchableFabric::find('F3');
    $fabric->update(['name' => 'Seda Natural']);
    $manager->reindex('fabrics', $fabric);

    expect(pecleFound('merino'))->toBe([])
        ->and(pecleFound('seda'))->toBe(['F3']);

    $new = PecleSearchableFabric::create(['id' => 'F5', 'name' => 'Denim Elástico', 'code' => null]);
    $manager->reindex('fabrics', $new);
    expect(pecleFound('elast'))->toBe(['F5']);

    $manager->unindex('fabrics', $new);
    expect(pecleFound('elast'))->toBe([]);
});

it('busca en varias palabras del mismo texto aunque vengan de columnas distintas', function () {
    app(PecleSearchManager::class)->rebuild('fabrics');

    expect(pecleFound('lana tel-010'))->toBe(['F3']); // nombre + código
});
