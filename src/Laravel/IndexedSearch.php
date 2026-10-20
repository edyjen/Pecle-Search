<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Contracts\TokenStore;
use Pecle\Search\Core\Normalizer;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
 * Nota: la comparación entity_id = llave del modelo es texto contra texto; con
 * llaves numéricas, algunos motores (PostgreSQL) piden un CAST.
 */
final class IndexedSearch
{
    private const MAX_WORDS = 8;

    public function __construct(
        private readonly TokenStore $tokens,
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

        $key = $query->getModel()->getQualifiedKeyName();
        foreach ($words as $word) {
            $query->whereExists(function (QueryBuilder $sub) use ($index, $key, $word): void {
                $sub->selectRaw('1')
                    ->from(DatabaseTokenStore::TOKENS)
                    ->where(DatabaseTokenStore::TOKENS.'.index_name', $index)
                    ->whereColumn(DatabaseTokenStore::TOKENS.'.entity_id', $key)
                    ->where(DatabaseTokenStore::TOKENS.'.token', 'like', $word.'%'); // [a-z0-9]: no hay comodines que escapar
            });
        }

        return $query;
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
