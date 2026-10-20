<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Completer;
use Pecle\Search\Core\Contracts\EntitySource;
use Pecle\Search\Core\Contracts\EntityTexts;
use Pecle\Search\Core\Corrector;
use Pecle\Search\Core\EditStage;
use Pecle\Search\Core\Indexer;
use Pecle\Search\Core\IndexRegistry;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\Resolution;
use Pecle\Search\Core\SearchEngine;
use Pecle\Search\Core\Suggestion;
use Pecle\Search\Core\Vocabulary;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Punto de entrada del módulo en Laravel: corrige queries, mantiene los
 * índices de búsqueda (completos o por entidad) y su vocabulario.
 *
 * Corregir es una mejora, nunca una condición para buscar: si el módulo está
 * apagado, el vocabulario no existe o algo falla, el query pasa tal cual.
 */
final class PecleSearchManager
{
    private ?Closure $enabledResolver = null;

    public function __construct(
        private readonly IndexRegistry $registry,
        private readonly Indexer $indexer,
        private readonly SearchEngine $engine,
        private readonly EditStage $editStage,
        private readonly KeyboardLayout $layout,
        private readonly Normalizer $normalizer,
        private readonly Completer $completer,
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * Reemplaza el interruptor de config('pecle_search.enabled') por una
     * función (p. ej. un parámetro editable en la interfaz). Se evalúa en
     * cada resolución. Apaga solo la corrección: la búsqueda indexada sigue.
     */
    public function enableUsing(?Closure $resolver): void
    {
        $this->enabledResolver = $resolver;
    }

    public function enabled(): bool
    {
        return (bool) ($this->enabledResolver !== null
            ? ($this->enabledResolver)()
            : $this->config->get('pecle_search.enabled', true));
    }

    /**
     * @param  bool  $log  dejar rastro si se corrige (el autocompletado lo apaga: consulta en cada pausa al teclear)
     */
    public function resolve(string $query, string $index, bool $log = true): Resolution
    {
        if (trim($query) === '' || ! $this->enabled()) {
            return Resolution::unchanged($query);
        }

        try {
            $trie = $this->registry->trie($index);
            if ($trie === null) {
                return Resolution::unchanged($query); // vocabulario sin construir
            }

            $edits = (bool) $this->config->get('pecle_search.edits.enabled', true);

            $resolution = (new Corrector(
                $trie,
                $this->engine,
                $this->normalizer,
                (int) $this->config->get('pecle_search.corrector.min_length', 3),
                (float) $this->config->get('pecle_search.corrector.cost_per_char', 1.0),
                (float) $this->config->get('pecle_search.corrector.min_cost', 2.0),
                (float) $this->config->get('pecle_search.corrector.min_margin', 0.0),
                $edits ? $this->editStage : null,
                (int) $this->config->get('pecle_search.edits.min_length', 4),
                $edits ? $this->layout->minSubstitutionCost() : 0.0,
                (bool) $this->config->get('pecle_search.edits.prefix_landing', false),
            ))->resolve($query);

            if ($log && $resolution->corrected() && $this->config->get('pecle_search.log_corrections', false)) {
                // Para calibrar con errores de tecleo reales: lo escrito, lo que se buscó y lo que costó.
                Log::info('pecle_search.corrected', [
                    'index' => $index,
                    'query' => $resolution->original,
                    'applied' => $resolution->applied,
                    'cost' => array_map(fn ($token) => round($token->cost, 2), array_values(array_filter($resolution->tokens, fn ($token) => $token->corrected()))),
                    'edit' => array_map(fn ($token) => $token->edit, array_values(array_filter($resolution->tokens, fn ($token) => $token->corrected()))),
                ]);
            }

            return $resolution;
        } catch (Throwable $e) {
            report($e);

            return Resolution::unchanged($query);
        }
    }

    /**
     * Autocompleta lo escrito con las palabras del índice, corrigiendo antes los errores de tecleo
     * (si la corrección está encendida). Sin índice, sin sugerencias o ante un fallo: lista vacía.
     *
     * @return list<Suggestion>
     */
    public function suggest(string $query, string $index, int $limit = 6): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            if (! $this->isBuilt($index)) {
                return [];
            }

            return $this->completer->suggest(
                $index,
                $this->resolve($query, $index, log: false),
                fn (array $ids) => $this->texts($index, $ids),
                $limit,
            );
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Reconstruye un índice completo desde sus fuentes y refresca su vocabulario. */
    public function rebuild(string $index): int
    {
        $count = $this->indexer->rebuild($index, array_values($this->sources($index)));
        $this->refreshVocabulary($index);

        return $count;
    }

    /** Vuelve a derivar el vocabulario del corrector desde el índice (tras cambios incrementales). */
    public function refreshVocabulary(string $index): Vocabulary
    {
        $vocabulary = $this->indexer->vocabulary($index);
        $this->registry->store($index, $vocabulary);

        return $vocabulary;
    }

    /** Reindexa una entidad (alta o cambio). Devuelve false si el índice no tiene fuente para ese modelo. */
    public function reindex(string $index, Model $model): bool
    {
        $source = $this->sourceFor($index, $model);
        if ($source === null) {
            return false;
        }

        $this->indexer->index($index, $model->getKey(), $source->textFor($model));

        return true;
    }

    public function unindex(string $index, Model $model): void
    {
        $this->indexer->remove($index, $model->getKey());
    }

    /** @return list<string> nombres de los índices configurados */
    public function indexes(): array
    {
        return array_keys((array) $this->config->get('pecle_search.indexes', []));
    }

    public function isBuilt(string $index): bool
    {
        return $this->indexer->isBuilt($index);
    }

    /**
     * El texto original de unas entidades del índice, de las fuentes que saben dar el suyo.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function texts(string $index, array $ids): array
    {
        $texts = [];
        foreach ($this->sources($index) as $source) {
            if ($source instanceof EntityTexts) {
                $texts += $source->texts($ids);
            }
        }

        return $texts;
    }

    /** @return array<int, EntitySource> */
    private function sources(string $index): array
    {
        $entries = $this->config->get("pecle_search.indexes.{$index}");
        if (! is_array($entries) || $entries === []) {
            throw new InvalidArgumentException("El índice de búsqueda [{$index}] no está configurado.");
        }

        return array_map(function ($entry): EntitySource {
            if (is_string($entry)) {
                return $this->container->make($entry);
            }
            if (is_array($entry) && isset($entry['model'], $entry['columns'])) {
                return new EloquentEntitySource($entry['model'], array_values((array) $entry['columns']));
            }

            throw new InvalidArgumentException('Fuente de búsqueda inválida: use una clase EntitySource o [model, columns].');
        }, $entries);
    }

    private function sourceFor(string $index, Model $model): ?EloquentEntitySource
    {
        foreach ($this->sources($index) as $source) {
            if ($source instanceof EloquentEntitySource && $model instanceof ($source->model())) {
                return $source;
            }
        }

        return null;
    }
}
