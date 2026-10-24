<?php

namespace Pecle\Search\Laravel;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Pecle\Search\Core\Normalizer;

/**
 * Filtra una consulta contra el índice de búsqueda: cada palabra del término
 * debe ser el comienzo de alguna palabra de la entidad (todas deben cumplirse).
 *
 *   Fabric::query()->whereIndexed('fabrics', 'algod lam', ['name', 'code'])
 *
 * Mientras el índice no se haya construido, cae al LIKE tradicional sobre
 * $fallback (columnas, o una función que recibe la consulta y el término): un
 * índice vacío devolvería cero resultados.
 *
 * **La tabla y la comparación salen del índice**: si sus fuentes declaran
 * `numeric_index => true`, el índice vive en `numeric_search_tokens` y se compara
 * entero con entero (la PK se usa); si no, en `search_tokens` y la llave del
 * modelo se convierte a texto cuando es numérica —sin esa conversión el motor no
 * usa el índice y la consulta se vuelve un producto cartesiano—.
 */
final class IndexedSearch
{
    private const MAX_WORDS = 8;

    public function __construct(
        private readonly DatabaseTokenStore $tokens,
        private readonly Normalizer $normalizer,
    ) {}

    /**
     * @param  Builder<*>  $query
     * @param  list<string>|Closure(Builder<*>, string): mixed  $fallback
     * @return Builder<*>
     */
    public function apply(Builder $query, string $index, string $term, array|Closure $fallback = []): Builder
    {
        if (! $this->tokens->isBuilt($index)) {
            return $this->fallback($query, $term, $fallback);
        }

        $words = $this->normalizer->searchTokens($term, self::MAX_WORDS);
        if ($words === []) {
            return $query->whereRaw('0 = 1'); // nada que buscar: ningún resultado
        }

        $model = $query->getModel();
        $table = $this->tokens->tokensTable($index);
        $comparison = $this->comparison($model, $index, $query->getConnection()->getDriverName());

        foreach ($words as $word) {
            $query->whereExists(function (QueryBuilder $sub) use ($table, $index, $comparison, $word): void {
                $sub->selectRaw('1')
                    ->from($table)
                    ->where($table.'.index_name', $index)
                    ->whereRaw($table.'.entity_id = '.$comparison)
                    ->where($table.'.token', 'like', $word.'%'); // [a-z0-9]: no hay comodines que escapar
            });
        }

        return $query;
    }

    /**
     * El lado derecho de `entity_id = …`: la llave del modelo, o su conversión a texto cuando la tabla es
     * de texto y la llave es numérica.
     */
    private function comparison(Model $model, string $index, string $driver): string
    {
        $key = $model->getQualifiedKeyName();
        $keyType = $model->getKeyType();
        $numericKey = in_array($keyType, ['int', 'integer'], true);

        if ($this->tokens->numeric($index)) {
            if (! $numericKey) {
                throw new InvalidArgumentException(
                    "El índice de búsqueda [{$index}] está declarado numérico (`numeric_index`) pero el modelo ".
                    '['.$model::class."] tiene id de texto. Revisá la fuente o sacá ese indicador."
                );
            }

            return $key;
        }

        return $numericKey ? $this->keyAsText($key, $driver) : $key;
    }

    /** `CAST` de la llave a texto, con el tipo que entiende cada motor. */
    private function keyAsText(string $key, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "CAST({$key} AS CHAR)",
            'sqlsrv' => "CAST({$key} AS NVARCHAR(36))",
            default => "CAST({$key} AS TEXT)",
        };
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<string>|Closure(Builder<*>, string): mixed  $columns
     * @return Builder<*>
     */
    private function fallback(Builder $query, string $term, array|Closure $columns): Builder
    {
        $term = trim($term);
        if ($term === '' || $columns === []) {
            return $query;
        }

        if ($columns instanceof Closure) {
            $query->where(fn (BuilderContract $q) => $columns($q, $term));

            return $query;
        }

        return $query->where(function (BuilderContract $q) use ($columns, $term): void {
            foreach ($columns as $column) {
                $q->orWhereLike($column, "%{$term}%");
            }
        });
    }
}
