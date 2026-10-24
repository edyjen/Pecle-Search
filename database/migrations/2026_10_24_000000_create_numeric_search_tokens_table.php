<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La tabla de tokens para índices con **id numérico** (`numeric_index => true` en la fuente) y la marca
 * de qué tabla usa cada índice.
 *
 * Por qué: `search_tokens.entity_id` es texto, y comparar una PK numérica contra texto impide que el
 * motor use el índice de la PK (medido con 200.000 pacientes: 15 ms contra 1 ms; sin el cast, 15 s).
 * Con `entity_id BIGINT` la búsqueda es un semi-join que arranca por el índice de tokens y entra a la
 * tabla por su PK.
 *
 * `search_indexes.table_name` dice en qué tabla está construido cada índice: si un proyecto cambia el
 * tipo —o un despliegue trae la tabla nueva vacía— `isBuilt()` lo detecta y la búsqueda cae al respaldo
 * (`LIKE`) en vez de devolver vacío en silencio. Las filas que ya existían toman el valor por defecto
 * `search_tokens`, así los índices de texto siguen "construidos" sin rehacer nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('numeric_search_tokens')) {
            Schema::create('numeric_search_tokens', function (Blueprint $table) {
                $table->string('index_name', 64);
                $table->unsignedBigInteger('entity_id');
                $table->string('token', 64);

                $table->primary(['index_name', 'entity_id', 'token']);
                $table->index(['index_name', 'token']);
            });
        }

        if (Schema::hasTable('search_indexes') && ! Schema::hasColumn('search_indexes', 'table_name')) {
            Schema::table('search_indexes', function (Blueprint $table) {
                $table->string('table_name', 64)->default('search_tokens');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('search_indexes') && Schema::hasColumn('search_indexes', 'table_name')) {
            Schema::table('search_indexes', function (Blueprint $table) {
                $table->dropColumn('table_name');
            });
        }

        Schema::dropIfExists('numeric_search_tokens');
    }
};
