<?php

use Pecle\Search\Core\Contracts\TokenStore;
use Pecle\Search\Core\Indexer;
use Pecle\Search\Laravel\IndexSynchronizer;
use Pecle\Search\Laravel\PecleSearchManager;
use Pecle\Search\Laravel\RefreshVocabularyJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Pecle\Search\Tests\TestCase;

uses(TestCase::class);

class PecleSyncFabric extends Model
{
    use SoftDeletes;

    protected $table = 'pecle_sync_fabrics';

    protected $guarded = [];

    public $timestamps = false;
}

class PecleSyncColor extends Model
{
    protected $table = 'pecle_sync_colors';

    protected $guarded = [];

    public $timestamps = false;

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(PecleSyncFabric::class, 'fabric_id');
    }
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true]);

    Schema::create('pecle_sync_fabrics', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('notes')->nullable();
        $table->softDeletes();
    });
    Schema::create('pecle_sync_colors', function (Blueprint $table) {
        $table->id();
        $table->foreignId('fabric_id');
        $table->string('color');
    });

    config(['pecle_search.indexes' => [
        'fabrics' => [['model' => PecleSyncFabric::class, 'columns' => ['name']]],
        'colors' => [[
            'model' => PecleSyncColor::class,
            'columns' => ['color', 'fabric.name'],
            'watch' => [['model' => PecleSyncFabric::class, 'foreign_key' => 'fabric_id', 'attributes' => ['name']]],
        ]],
    ]]);
    app(IndexSynchronizer::class)->register();
});

function pecleSyncTokens(string $index, int $entity): array
{
    return DB::table('search_tokens')->where('index_name', $index)->where('entity_id', (string) $entity)->orderBy('token')->pluck('token')->all();
}

function pecleSyncBuild(): void
{
    app(PecleSearchManager::class)->rebuild('fabrics');
    app(PecleSearchManager::class)->rebuild('colors');
}

it('reindexa una entidad al guardarla, editarla y borrarla', function () {
    pecleSyncBuild();

    $fabric = PecleSyncFabric::create(['name' => 'Lana Merino']);
    expect(pecleSyncTokens('fabrics', $fabric->id))->toBe(['lana', 'merino']);

    $fabric->update(['name' => 'Algodón Peinado']);
    expect(pecleSyncTokens('fabrics', $fabric->id))->toBe(['algodon', 'peinado']);

    $fabric->delete(); // soft delete: sale del índice
    expect(pecleSyncTokens('fabrics', $fabric->id))->toBe([]);

    $fabric->restore();
    expect(pecleSyncTokens('fabrics', $fabric->id))->toBe(['algodon', 'peinado']);
});

it('no escribe nada mientras el índice no se haya construido', function () {
    PecleSyncFabric::create(['name' => 'Lana Merino']);

    expect(DB::table('search_tokens')->count())->toBe(0);
});

it('reindexa las entidades que dependen de un modelo vigilado cuando cambia lo que se indexa', function () {
    $fabric = PecleSyncFabric::create(['name' => 'Lana Merino', 'notes' => 'a']);
    $color = PecleSyncColor::create(['fabric_id' => $fabric->id, 'color' => 'Azul']);
    $other = PecleSyncColor::create(['fabric_id' => PecleSyncFabric::create(['name' => 'Denim'])->id, 'color' => 'Rojo']);
    pecleSyncBuild();

    expect(pecleSyncTokens('colors', $color->id))->toBe(['azul', 'lana', 'merino']);

    // Un atributo que no se indexa no dispara nada: el centinela sobrevive.
    DB::table('search_tokens')->insert(['index_name' => 'colors', 'entity_id' => (string) $color->id, 'token' => 'centinela']);
    $fabric->update(['notes' => 'b']);
    expect(pecleSyncTokens('colors', $color->id))->toContain('centinela');

    // Cambiar el nombre sí reindexa sus colores (y solo los suyos).
    $fabric->update(['name' => 'Lana Cardada']);
    expect(pecleSyncTokens('colors', $color->id))->toBe(['azul', 'cardada', 'lana'])
        ->and(pecleSyncTokens('colors', $other->id))->toBe(['denim', 'rojo']);
});

it('refresca el vocabulario con un job único con retraso', function () {
    Queue::fake();
    pecleSyncBuild();

    PecleSyncFabric::create(['name' => 'Gabardina']);
    PecleSyncFabric::create(['name' => 'Popelina']);

    Queue::assertPushed(RefreshVocabularyJob::class, fn ($job) => $job->index === 'fabrics' && $job->delay !== null);
});

it('las palabras nuevas se vuelven corregibles al refrescarse el vocabulario', function () {
    pecleSyncBuild();
    $manager = tap(app(PecleSearchManager::class), fn (PecleSearchManager $m) => $m->enableUsing(null));

    PecleSyncFabric::create(['name' => 'Gabardina']); // la cola de pruebas es síncrona: el job corre al instante

    expect($manager->resolve('Gabardona', 'fabrics')->applied)->toBe('Gabardina');
});

it('no reindexa si la transacción se revierte', function () {
    pecleSyncBuild();

    try {
        DB::transaction(function () {
            PecleSyncFabric::create(['name' => 'Fantasma']);
            throw new RuntimeException('revertida');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('search_tokens')->where('token', 'fantasma')->count())->toBe(0);
});

it('un fallo al sincronizar se reporta pero no impide guardar', function () {
    pecleSyncBuild();
    Exceptions::fake();
    $this->app->instance(TokenStore::class, new class implements TokenStore
    {
        public function replace(string $index, string $entityId, array $tokens): void
        {
            throw new RuntimeException('base de datos del índice caída');
        }

        public function remove(string $index, string $entityId): void {}

        public function replaceAll(string $index, iterable $entities): int
        {
            return 0;
        }

        public function frequencies(string $index): array
        {
            return [];
        }

        public function isBuilt(string $index): bool
        {
            return true;
        }

        public function forget(string $index): void {}
    });
    app()->forgetInstance(PecleSearchManager::class);
    app()->forgetInstance(Indexer::class);

    $fabric = PecleSyncFabric::create(['name' => 'Seda']);

    expect($fabric->exists)->toBeTrue()
        ->and(PecleSyncFabric::count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('no escucha nada si la sincronización está apagada', function () {
    config(['pecle_search.sync.enabled' => false]);
    $this->app->forgetInstance(IndexSynchronizer::class);
    $before = count(Event::getRawListeners());
    // (los escuchadores de la prueba ya registrados no se pueden quitar: se comprueba que register() no agrega)
    app(IndexSynchronizer::class)->register();

    expect(count(Event::getRawListeners()))->toBe($before);
});
