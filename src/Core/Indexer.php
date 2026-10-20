<?php

namespace Pecle\Search\Core;

use Pecle\Search\Core\Contracts\EntitySource;
use Pecle\Search\Core\Contracts\TokenStore;

/**
 * Mantiene el índice de búsqueda: tokeniza el texto de cada entidad y se lo
 * entrega al TokenStore. Sabe reconstruir un índice entero desde sus fuentes
 * y reindexar una sola entidad cuando cambia.
 */
final class Indexer
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly Normalizer $normalizer,
    ) {}

    /** Indexa (o reindexa) una entidad. Un texto sin palabras equivale a quitarla. */
    public function index(string $index, string|int $entityId, string $text): void
    {
        $tokens = $this->normalizer->searchTokens($text);

        if ($tokens === []) {
            $this->tokens->remove($index, (string) $entityId);

            return;
        }

        $this->tokens->replace($index, (string) $entityId, $tokens);
    }

    public function remove(string $index, string|int $entityId): void
    {
        $this->tokens->remove($index, (string) $entityId);
    }

    /**
     * Reconstruye el índice desde cero. Si una entidad aparece en varias
     * fuentes se unen sus palabras.
     *
     * @param  iterable<EntitySource>  $sources
     * @return int cantidad de entidades indexadas
     */
    public function rebuild(string $index, iterable $sources): int
    {
        /** @var array<string, list<string>> $entities */
        $entities = [];

        foreach ($sources as $source) {
            foreach ($source->entities() as $id => $text) {
                $tokens = $this->normalizer->searchTokens((string) $text);
                if ($tokens === []) {
                    continue;
                }

                $id = (string) $id;
                $entities[$id] = isset($entities[$id])
                    ? array_values(array_unique([...$entities[$id], ...$tokens]))
                    : $tokens;
            }
        }

        return $this->tokens->replaceAll($index, $entities);
    }

    /** El vocabulario del corrector, derivado de lo que hay indexado. */
    public function vocabulary(string $index): Vocabulary
    {
        return Vocabulary::fromFrequencies($this->tokens->frequencies($index));
    }

    public function isBuilt(string $index): bool
    {
        return $this->tokens->isBuilt($index);
    }

    public function forget(string $index): void
    {
        $this->tokens->forget($index);
    }
}
