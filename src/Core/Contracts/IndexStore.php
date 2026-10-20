<?php

namespace Pecle\Search\Core\Contracts;

use Pecle\Search\Core\Vocabulary;

/**
 * Dónde se guardan los vocabularios, uno por índice con nombre ("fabrics",
 * "colors"…). `version()` debe ser barato: se consulta en cada búsqueda para
 * saber si el índice en memoria sigue vigente.
 */
interface IndexStore
{
    public function get(string $name): ?Vocabulary;

    public function put(string $name, Vocabulary $vocabulary): void;

    public function forget(string $name): void;

    /** Huella del vocabulario guardado, o null si no hay. */
    public function version(string $name): ?string;
}
