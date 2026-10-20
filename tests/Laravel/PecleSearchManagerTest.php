<?php

use Pecle\Search\Core\Contracts\EntitySource;
use Pecle\Search\Core\Contracts\IndexStore;
use Pecle\Search\Core\Vocabulary;
use Pecle\Search\Laravel\PecleSearchManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Pecle\Search\Tests\TestCase;

uses(TestCase::class);

class PecleItem extends Model
{
    use SoftDeletes;

    protected $table = 'pecle_items';

    protected $guarded = [];

    public $timestamps = false;
}

class PecleVariant extends Model
{
    protected $table = 'pecle_variants';

    protected $guarded = [];

    public $timestamps = false;

    public function item(): BelongsTo
    {
        return $this->belongsTo(PecleItem::class, 'item_id');
    }
}

class PecleStaticSource implements EntitySource
{
    public function entities(): iterable
    {
        return ['G1' => 'Gabardina Elastizada', 'G2' => 'Gabardina'];
    }
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true]);

    Schema::create('pecle_items', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('color')->nullable();
        $table->softDeletes();
    });
    Schema::create('pecle_variants', function (Blueprint $table) {
        $table->id();
        $table->foreignId('item_id');
        $table->string('code');
    });

    foreach ([['Lana Merino', 'Azul'], ['Lana Cardada', 'Azul Marino'], ['Algodón Peinado', null], ['Denim', 'Índigo']] as [$name, $color]) {
        PecleItem::create(['name' => $name, 'color' => $color]);
    }

    // Solo los índices de la prueba: los del proyecto apuntan a tablas que acá no existen.
    config(['pecle_search.indexes' => ['items' => [['model' => PecleItem::class, 'columns' => ['name', 'color']]]]]);
});

function pecleManager(): PecleSearchManager
{
    // El proyecto ata el interruptor a su tabla de parámetros; los tests del módulo no la tienen.
    return tap(app(PecleSearchManager::class), fn (PecleSearchManager $manager) => $manager->enableUsing(null));
}

function pecleTokens(string $index, string|int $entity): array
{
    return DB::table('search_tokens')->where('index_name', $index)->where('entity_id', (string) $entity)->orderBy('token')->pluck('token')->all();
}

it('trae la configuración por defecto del módulo', function () {
    expect(config('pecle_search.enabled'))->toBeTrue()
        ->and(config('pecle_search.corrector.min_length'))->toBe(3)
        ->and(config('pecle_search.engine.top_k'))->toBe(24);
});

it('reconstruye un índice desde las columnas de un modelo, una fila por entidad', function () {
    PecleItem::where('name', 'Denim')->first()->delete(); // borrada: no debe entrar

    expect(pecleManager()->rebuild('items'))->toBe(3)
        ->and(pecleTokens('items', 1))->toBe(['azul', 'lana', 'merino'])
        ->and(pecleTokens('items', 2))->toBe(['azul', 'cardada', 'lana', 'marino'])
        ->and(pecleTokens('items', 3))->toBe(['algodon', 'peinado'])
        ->and(pecleTokens('items', 4))->toBe([])
        ->and(pecleManager()->isBuilt('items'))->toBeTrue();
});

it('toma columnas de relaciones con notación de punto', function () {
    PecleVariant::create(['item_id' => 1, 'code' => 'V-100']);
    config(['pecle_search.indexes.variants' => [['model' => PecleVariant::class, 'columns' => ['code', 'item.name']]]]);

    pecleManager()->rebuild('variants');

    expect(pecleTokens('variants', 1))->toBe(['100', 'lana', 'merino', 'v']);
});

it('acepta una clase EntitySource como fuente', function () {
    config(['pecle_search.indexes.static' => [PecleStaticSource::class]]);

    expect(pecleManager()->rebuild('static'))->toBe(2)
        ->and(pecleTokens('static', 'G1'))->toBe(['elastizada', 'gabardina']);
});

it('deja pasar el query mientras no hay vocabulario', function () {
    $resolution = pecleManager()->resolve('Lqna', 'items');

    expect($resolution->applied)->toBe('Lqna')->and($resolution->corrected())->toBeFalse();
});

it('corrige con el vocabulario derivado del índice y describe la resolución para la API', function () {
    pecleManager()->rebuild('items');

    $resolution = pecleManager()->resolve('Lqna cardsda', 'items');

    expect($resolution->applied)->toBe('Lana cardada')
        ->and($resolution->toArray())->toBe(['query' => 'Lqna cardsda', 'applied' => 'Lana cardada', 'corrected' => true])
        ->and(pecleManager()->resolve('Lana', 'items')->toArray())->toBe(['query' => 'Lana', 'applied' => 'Lana', 'corrected' => false]);
});

it('las palabras nuevas se corrigen solo cuando se refresca el vocabulario', function () {
    pecleManager()->rebuild('items');
    $new = PecleItem::create(['name' => 'Gabardina', 'color' => null]);
    pecleManager()->reindex('items', $new);

    expect(pecleManager()->resolve('Gabardona', 'items')->corrected())->toBeFalse(); // aún no está en el vocabulario

    pecleManager()->refreshVocabulary('items');

    expect(pecleManager()->resolve('Gabardona', 'items')->applied)->toBe('Gabardina');
});

it('reindex devuelve false si el índice no tiene fuente para ese modelo', function () {
    expect(pecleManager()->reindex('items', PecleVariant::create(['item_id' => 1, 'code' => 'X'])))->toBeFalse();
});

it('no corrige si el módulo está apagado', function () {
    pecleManager()->rebuild('items');
    config(['pecle_search.enabled' => false]);

    expect(pecleManager()->resolve('Lqna', 'items')->corrected())->toBeFalse();
});

it('el interruptor se puede delegar en el proyecto', function () {
    pecleManager()->rebuild('items');
    $manager = pecleManager();

    $on = true;
    $manager->enableUsing(function () use (&$on) {
        return $on;
    });
    config(['pecle_search.enabled' => false]); // el resolver manda sobre la config

    expect($manager->resolve('Lqna', 'items')->corrected())->toBeTrue();

    $on = false;
    expect($manager->resolve('Lqna', 'items')->corrected())->toBeFalse();

    $manager->enableUsing(null);
    expect($manager->enabled())->toBeFalse(); // vuelve a la config
});

it('respeta los ajustes del corrector en la config', function () {
    pecleManager()->rebuild('items');
    config(['pecle_search.corrector.cost_per_char' => 0.1, 'pecle_search.corrector.min_cost' => 0.5]);

    expect(pecleManager()->resolve('Lqna', 'items')->corrected())->toBeFalse();
});

it('no toca queries vacíos', function () {
    pecleManager()->rebuild('items');

    expect(pecleManager()->resolve('   ', 'items')->applied)->toBe('   ');
});

it('rechaza reconstruir un índice sin configurar', function () {
    expect(fn () => pecleManager()->rebuild('nada'))->toThrow(InvalidArgumentException::class);
});

it('nunca rompe la búsqueda: ante un fallo reporta y deja pasar el query', function () {
    Exceptions::fake();
    $this->app->instance(IndexStore::class, new class implements IndexStore
    {
        public function get(string $name): ?Vocabulary
        {
            throw new RuntimeException('caché caída');
        }

        public function put(string $name, Vocabulary $vocabulary): void {}

        public function forget(string $name): void {}

        public function version(string $name): ?string
        {
            throw new RuntimeException('caché caída');
        }
    });

    $resolution = pecleManager()->resolve('Lqna', 'items');

    expect($resolution->applied)->toBe('Lqna');
    Exceptions::assertReported(RuntimeException::class);
});

it('pecle-search:rebuild reconstruye los índices pedidos o todos', function () {
    $this->artisan('pecle-search:rebuild items')->assertSuccessful();
    expect(pecleManager()->resolve('Lqna', 'items')->corrected())->toBeTrue();

    config(['pecle_search.indexes.static' => [PecleStaticSource::class]]);
    $this->artisan('pecle-search:rebuild')->assertSuccessful();
    expect(pecleManager()->resolve('Gabardona', 'static')->applied)->toBe('Gabardina');
});

it('pecle-search:rebuild falla con un índice desconocido y avisa si no hay ninguno', function () {
    $this->artisan('pecle-search:rebuild nada')->assertFailed();

    config(['pecle_search.indexes' => []]);
    $this->artisan('pecle-search:rebuild')->assertSuccessful();
});

it('pecle-search:evaluate mide el corrector con el vocabulario del índice', function () {
    foreach (['Jersey Liso', 'Franela Estampada', 'Gabardina Elastizada', 'Terciopelo Negro', 'Popelina Blanca', 'Mezclilla Celeste'] as $name) {
        PecleItem::create(['name' => $name, 'color' => null]);
    }
    pecleManager()->rebuild('items');

    $this->artisan('pecle-search:evaluate items --sample=20')
        ->expectsOutputToContain('Palabras legítimas cambiadas')
        ->assertSuccessful();
});

it('pecle-search:evaluate falla si el índice no está configurado o no se ha construido', function () {
    $this->artisan('pecle-search:evaluate nada')->assertFailed();
    $this->artisan('pecle-search:evaluate items')->assertFailed(); // configurado, pero sin construir
});

it('registra las correcciones aplicadas cuando se pide, y solo esas', function () {
    pecleManager()->rebuild('items');
    config(['pecle_search.log_corrections' => true]);
    Log::spy();

    pecleManager()->resolve('Lana', 'items');       // ya estaba bien: no se registra
    pecleManager()->resolve('Lqna cardsda', 'items');

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'pecle_search.corrected'
        && $context['index'] === 'items'
        && $context['query'] === 'Lqna cardsda'
        && $context['applied'] === 'Lana cardada'
        && count($context['cost']) === 2);
});

it('no registra correcciones si no se pide', function () {
    pecleManager()->rebuild('items');
    config(['pecle_search.log_corrections' => false]);
    Log::spy();

    pecleManager()->resolve('Lqna', 'items');

    Log::shouldNotHaveReceived('info');
});

it('corrige letras de más, de menos y cruzadas con la etapa 2, y se puede apagar', function () {
    pecleManager()->rebuild('items');

    $on = pecleManager()->resolve('Lana Cardda', 'items');   // falta una letra: la capa 1 no la puede alinear
    config(['pecle_search.edits.enabled' => false]);
    $off = pecleManager()->resolve('Lana Cardda', 'items');

    expect($on->applied)->toBe('Lana Cardada')
        ->and($on->tokens[1]->edit)->toBe('missing_letter')
        ->and($off->applied)->toBe('Lana Cardda');
});

it('con prefix_landing (encendido por defecto) corrige también lo que se está escribiendo, y se puede apagar', function () {
    pecleManager()->rebuild('items');

    expect(config('pecle_search.edits.prefix_landing'))->toBeTrue();

    config(['pecle_search.edits.prefix_landing' => false]);
    expect(pecleManager()->resolve('Cardd', 'items')->applied)->toBe('Cardd');   // "cardd" no es el comienzo de ninguna palabra

    config(['pecle_search.edits.prefix_landing' => true]);
    $resolution = pecleManager()->resolve('Cardd', 'items');

    expect($resolution->applied)->toBe('Card')->and($resolution->tokens[0]->edit)->toBe('prefix'); // la d repetida sobra
});

it('el registro de correcciones dice qué tipo de edición fue', function () {
    pecleManager()->rebuild('items');
    config(['pecle_search.log_corrections' => true]);
    Log::spy();

    pecleManager()->resolve('Lqna Cardda', 'items');

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context['edit'] === ['keyboard', 'missing_letter']);
});

it('pecle-search:evaluate compara con y sin la etapa 2', function () {
    foreach (['Jersey Liso', 'Franela Estampada', 'Gabardina Elastizada', 'Terciopelo Negro', 'Popelina Blanca', 'Mezclilla Celeste'] as $name) {
        PecleItem::create(['name' => $name, 'color' => null]);
    }
    pecleManager()->rebuild('items');

    $this->artisan('pecle-search:evaluate items --sample=20 --edits=both')
        ->expectsOutputToContain('con etapa 2')
        ->assertSuccessful();
    $this->artisan('pecle-search:evaluate items --edits=quizas')->assertFailed();
});
