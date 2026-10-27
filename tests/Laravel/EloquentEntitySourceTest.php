<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pecle\Search\Laravel\EloquentEntitySource;
use Pecle\Search\Tests\TestCase;

uses(TestCase::class);

class PecleSourceBrand extends Model
{
    protected $table = 'pecle_source_brands';

    protected $guarded = [];

    public $timestamps = false;
}

class PecleSourcePlace extends Model
{
    protected $table = 'pecle_source_places';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['address' => 'array'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(PecleSourceBrand::class, 'brand_id');
    }
}

beforeEach(function () {
    Schema::create('pecle_source_brands', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('pecle_source_places', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('brand_id')->nullable();
        $table->json('address')->nullable();
    });
});

it('lee una columna dentro de un atributo array sin tratarla como relación', function () {
    PecleSourcePlace::create(['name' => 'Cumaná', 'address' => ['state' => 'Sucre']]);

    $source = new EloquentEntitySource(PecleSourcePlace::class, ['name', 'address.state']);

    expect(iterator_to_array($source->entities()))->toBe([1 => 'Cumaná Sucre']);
});

it('sigue cargando relaciones con notación de punto', function () {
    $brand = PecleSourceBrand::create(['name' => 'Zulia']);
    PecleSourcePlace::create(['name' => 'Maracaibo', 'brand_id' => $brand->id]);

    $source = new EloquentEntitySource(PecleSourcePlace::class, ['name', 'brand.name']);

    expect(iterator_to_array($source->entities()))->toBe([1 => 'Maracaibo Zulia']);
});

it('omite la clave que falta en el JSON', function () {
    PecleSourcePlace::create(['name' => 'Cumanacoa', 'address' => []]);

    $source = new EloquentEntitySource(PecleSourcePlace::class, ['name', 'address.state']);

    expect(iterator_to_array($source->entities()))->toBe([1 => 'Cumanacoa']);
    expect($source->texts([1]))->toBe(['1' => 'Cumanacoa']);
});
