<?php

namespace Pecle\Search\Core\Contracts;

/**
 * El índice de búsqueda propiamente dicho: qué palabras (tokens) tiene cada
 * entidad de cada índice con nombre. Es la fuente de verdad: de aquí salen
 * tanto los resultados como el vocabulario del corrector.
 */
interface TokenStore
{
    /**
     * Reemplaza los tokens de una entidad.
     *
     * @param  list<string>  $tokens
     */
    public function replace(string $index, string $entityId, array $tokens): void;

    public function remove(string $index, string $entityId): void;

    /**
     * Reemplaza TODO el índice de forma atómica y lo marca como construido.
     * Quien consulta ve el índice anterior completo o el nuevo completo.
     *
     * @param  iterable<string|int, list<string>>  $entities  id de la entidad => tokens
     * @return int cantidad de entidades indexadas
     */
    public function replaceAll(string $index, iterable $entities): int;

    /** @return array<string|int, int> token => cantidad de entidades que lo tienen */
    public function frequencies(string $index): array;

    /** ¿Se construyó alguna vez el índice? Antes de eso no sirve para buscar. */
    public function isBuilt(string $index): bool;

    public function forget(string $index): void;
}
