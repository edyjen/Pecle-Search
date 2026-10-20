<?php

namespace Pecle\Search\Core\Contracts;

/**
 * Lo que necesita el autocompletado del índice de búsqueda: qué palabras
 * siguen a un comienzo, dentro de las entidades que ya contienen ciertas
 * palabras. Va aparte de TokenStore para que quien solo busca no lo implemente.
 */
interface CompletionStore
{
    /**
     * Palabras que empiezan con $prefix (o todas, si es vacío) en las entidades que tienen
     * cada palabra de $context (como comienzo de palabra). No devuelve las del propio contexto.
     * Ordenadas: la palabra exacta primero, luego por cantidad de entidades que la usan y por
     * orden alfabético.
     *
     * @param  list<string>  $context  palabras ya plegadas
     * @return list<array{token: string, entities: int, ids: list<string>}> $ids = hasta dos entidades que la tienen (para recuperar cómo se escribe de verdad)
     */
    public function completions(string $index, array $context, string $prefix, int $limit): array;
}
