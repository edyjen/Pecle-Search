<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pecle\Search\Laravel\DatabaseTokenStore;
use Pecle\Search\Laravel\PecleSearchManager;
use Pecle\Search\Tests\TestCase;

uses(TestCase::class);

class PecleNumericPatient extends Model
{
    protected $table = 'pecle_numeric_patients';

    protected $guarded = [];

    public $timestamps = false;
}

class PecleTextualPatient extends Model
{
    protected $table = 'pecle_textual_patients';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true]);

    Schema::create('pecle_numeric_patients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('pecle_textual_patients', function (Blueprint $table) {
        $table->string('id', 26)->primary();
        $table->string('name');
    });

    foreach (['Ana González', 'Luis González', 'Rosa Martínez'] as $name) {
        PecleNumericPatient::create(['name' => $name]);
    }
    PecleTextualPatient::create(['id' => 'P1', 'name' => 'Pedro González']);

    config(['pecle_search.indexes.patients' => [[
        'model' => PecleNumericPatient::class,
        'columns' => ['name'],
        'numeric_index' => true,
    ]]]);
});

it('construye el índice en la tabla numérica y encuentra sin CAST', function () {
    app(PecleSearchManager::class)->rebuild('patients');

    $store = app(DatabaseTokenStore::class);

    expect($store->isBuilt('patients'))->toBeTrue()
        ->and($store->tokensTable('patients'))->toBe(DatabaseTokenStore::NUMERIC_TOKENS)
        ->and(DB::table(DatabaseTokenStore::NUMERIC_TOKENS)->where('index_name', 'patients')->count())->toBeGreaterThan(0)
        ->and(DB::table(DatabaseTokenStore::TOKENS)->where('index_name', 'patients')->count())->toBe(0);

    $ids = PecleNumericPatient::query()
        ->whereIndexed('patients', 'gonz', ['name'])
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([1, 2]);
});

it('un índice de texto con id numérico sigue funcionando con el CAST', function () {
    config(['pecle_search.indexes.patients' => [[
        'model' => PecleNumericPatient::class,
        'columns' => ['name'],
    ]]]);

    app(PecleSearchManager::class)->rebuild('patients');

    $store = app(DatabaseTokenStore::class);
    expect($store->tokensTable('patients'))->toBe(DatabaseTokenStore::TOKENS);

    $ids = PecleNumericPatient::query()->whereIndexed('patients', 'mart', ['name'])->pluck('id')->all();
    expect($ids)->toBe([3]);
});

it('no da por construido el índice si la marca apunta a la otra tabla', function () {
    app(PecleSearchManager::class)->rebuild('patients');

    DB::table(DatabaseTokenStore::INDEXES)->where('index_name', 'patients')->update(['table_name' => DatabaseTokenStore::TOKENS]);

    expect(app(DatabaseTokenStore::class)->isBuilt('patients'))->toBeFalse();

    // Y la búsqueda cae al respaldo, que igual encuentra.
    expect(PecleNumericPatient::query()->whereIndexed('patients', 'gonz', ['name'])->count())->toBe(2);
});

it('rechaza un id de texto en un índice declarado numérico', function () {
    config(['pecle_search.indexes.patients' => [[
        'model' => PecleTextualPatient::class,
        'columns' => ['name'],
        'numeric_index' => true,
    ]]]);

    expect(fn () => app(PecleSearchManager::class)->rebuild('patients'))
        ->toThrow(InvalidArgumentException::class);
});

it('rechaza un índice que mezcla entidades numéricas y de texto', function () {
    config(['pecle_search.indexes.mixed' => [
        ['model' => PecleNumericPatient::class, 'columns' => ['name'], 'numeric_index' => true],
        ['model' => PecleTextualPatient::class, 'columns' => ['name']],
    ]]);

    expect(fn () => app(DatabaseTokenStore::class)->numeric('mixed'))
        ->toThrow(InvalidArgumentException::class);
});
