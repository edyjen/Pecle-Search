<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tablas del módulo PecleSearch.
 *
 * search_tokens: el índice invertido. Una fila por (índice, entidad, palabra).
 * La llave primaria empieza por (índice, entidad) a propósito: la búsqueda es
 * un EXISTS correlacionado por entidad, así que cada comprobación recorre solo
 * las pocas palabras de esa entidad. El segundo índice sirve para contar
 * entidades por palabra (vocabulario del corrector).
 *
 * search_indexes: marca de qué índices se construyeron, para no buscar contra
 * un índice vacío.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Las guardas permiten que un proyecto que ya tenía estas tablas (copiadas
        // antes de existir el paquete) adopte el paquete sin errores.
        if (! Schema::hasTable('search_tokens')) {
            Schema::create('search_tokens', function (Blueprint $table) {
                $table->string('index_name', 64);
                $table->string('entity_id', 36); // ULID/UUID/id numérico como texto
                $table->string('token', 64);

                $table->primary(['index_name', 'entity_id', 'token']);
                $table->index(['index_name', 'token']);
            });
        }

        if (! Schema::hasTable('search_indexes')) {
            Schema::create('search_indexes', function (Blueprint $table) {
                $table->string('index_name', 64)->primary();
                $table->unsignedInteger('entities');
                $table->timestamp('built_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_indexes');
        Schema::dropIfExists('search_tokens');
    }
};
