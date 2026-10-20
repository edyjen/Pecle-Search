<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Contracts\IndexStore;
use Pecle\Search\Core\Vocabulary;
use Illuminate\Contracts\Cache\Repository;

/**
 * Guarda los vocabularios en la caché de Laravel (Redis en producción). El
 * vocabulario va en una llave y su huella en otra, para que la consulta de
 * versión de cada búsqueda no tenga que leer ni decodificar el contenido.
 */
final class CacheIndexStore implements IndexStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'pecle_search',
    ) {}

    public function get(string $name): ?Vocabulary
    {
        $json = $this->cache->get($this->key($name, 'words'));

        return is_string($json) ? Vocabulary::fromJson($json) : null;
    }

    public function put(string $name, Vocabulary $vocabulary): void
    {
        // Primero el contenido y después la huella: quien ve la versión nueva ya encuentra el vocabulario.
        $this->cache->forever($this->key($name, 'words'), $vocabulary->toJson());
        $this->cache->forever($this->key($name, 'version'), $vocabulary->version());
    }

    public function forget(string $name): void
    {
        $this->cache->forget($this->key($name, 'version'));
        $this->cache->forget($this->key($name, 'words'));
    }

    public function version(string $name): ?string
    {
        $version = $this->cache->get($this->key($name, 'version'));

        return is_string($version) ? $version : null;
    }

    private function key(string $name, string $part): string
    {
        return "{$this->prefix}:{$name}:{$part}";
    }
}
