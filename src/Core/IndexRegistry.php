<?php

namespace Pecle\Search\Core;

use Pecle\Search\Core\Contracts\IndexStore;

/**
 * Entrega el Trie de cada índice y lo conserva en memoria mientras el
 * vocabulario guardado no cambie. En un proceso de vida corta (PHP-FPM) cuesta
 * una lectura y un armado por petición; en uno persistente (Octane,
 * FrankenPHP en modo worker) solo la consulta de versión.
 *
 * El vocabulario es una copia derivada del índice de búsqueda (ver Indexer):
 * aquí solo se guarda en caché y se arma el trie.
 */
final class IndexRegistry
{
    /** @var array<string, array{0: string, 1: Trie}> nombre => [versión, trie] */
    private array $loaded = [];

    public function __construct(
        private readonly IndexStore $store,
        private readonly int $topK = 24,
    ) {}

    /** El trie del índice, o null si todavía no hay vocabulario. */
    public function trie(string $name): ?Trie
    {
        $version = $this->store->version($name);
        if ($version === null) {
            unset($this->loaded[$name]);

            return null;
        }

        if (($this->loaded[$name][0] ?? null) === $version) {
            return $this->loaded[$name][1];
        }

        $vocabulary = $this->store->get($name);
        if ($vocabulary === null) {
            unset($this->loaded[$name]);

            return null;
        }

        // Se memoiza con la versión del contenido leído, no la consultada: si cambió entre las dos lecturas, la próxima recarga.
        $this->loaded[$name] = [$vocabulary->version(), $vocabulary->toTrie($this->topK)];

        return $this->loaded[$name][1];
    }

    public function store(string $name, Vocabulary $vocabulary): void
    {
        $this->store->put($name, $vocabulary);
        unset($this->loaded[$name]);
    }

    public function forget(string $name): void
    {
        $this->store->forget($name);
        unset($this->loaded[$name]);
    }
}
