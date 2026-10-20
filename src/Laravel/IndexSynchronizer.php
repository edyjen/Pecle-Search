<?php

namespace Pecle\Search\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mantiene los índices al día cuando cambian los modelos que los alimentan:
 * al guardar, borrar o restaurar una entidad se reindexa (o se quita) esa
 * sola entidad, una vez confirmada la transacción. Cada entrada de
 * `pecle_search.indexes` con [model, columns] se vigila sola.
 *
 * Una entrada puede declarar `watch`: modelos relacionados cuyo cambio afecta
 * el texto de la entidad (p. ej. el nombre de la ficha dentro del texto de
 * sus colores):
 *
 *   'watch' => [['model' => Fabric::class, 'foreign_key' => 'fabric_id', 'attributes' => ['name']]]
 *
 * Cuando el modelo vigilado cambia (en alguno de esos atributos), se
 * reindexan las entidades cuya `foreign_key` apunta a él.
 *
 * Solo se sincronizan los índices ya construidos. Lo que se escriba sin pasar
 * por eventos de Eloquent (consultas masivas, `DB::table`) no se ve aquí: ahí
 * hay que reconstruir con `pecle-search:rebuild`.
 *
 * Un fallo al sincronizar se reporta pero nunca rompe el guardado.
 */
final class IndexSynchronizer
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Dispatcher $events,
    ) {}

    /** Registra los escuchadores según la config actual. */
    public function register(): void
    {
        if (! $this->config->get('pecle_search.sync.enabled', true)) {
            return;
        }

        foreach ((array) $this->config->get('pecle_search.indexes', []) as $index => $entries) {
            foreach ((array) $entries as $entry) {
                if (! is_array($entry) || ! isset($entry['model'], $entry['columns'])) {
                    continue;
                }

                $this->listenToEntity((string) $index, $entry['model']);

                foreach ((array) ($entry['watch'] ?? []) as $watch) {
                    $this->listenToWatched((string) $index, $entry['model'], $watch);
                }
            }
        }
    }

    private function listenToEntity(string $index, string $modelClass): void
    {
        foreach (['saved', 'restored'] as $event) {
            $this->events->listen("eloquent.{$event}: {$modelClass}", fn (Model $model) => $this->safely(
                fn () => $this->afterCommit(fn () => $this->reindex($index, $model)),
            ));
        }

        $this->events->listen("eloquent.deleted: {$modelClass}", fn (Model $model) => $this->safely(
            fn () => $this->afterCommit(fn () => $this->unindex($index, $model)),
        ));
    }

    /** @param array{model: class-string<Model>, foreign_key: string, attributes?: list<string>} $watch */
    private function listenToWatched(string $index, string $dependentClass, array $watch): void
    {
        $this->events->listen("eloquent.saved: {$watch['model']}", function (Model $watched) use ($index, $dependentClass, $watch): void {
            $attributes = (array) ($watch['attributes'] ?? []);
            if ($attributes !== [] && ! $watched->wasChanged($attributes)) {
                return; // no cambió nada de lo que se indexa
            }

            $this->safely(fn () => $this->afterCommit(fn () => $this->reindexDependents($index, $dependentClass, $watch['foreign_key'], $watched)));
        });
    }

    private function reindex(string $index, Model $model): void
    {
        $manager = $this->manager();
        if ($manager->isBuilt($index) && $manager->reindex($index, $model)) {
            $this->scheduleVocabularyRefresh($index);
        }
    }

    private function unindex(string $index, Model $model): void
    {
        $manager = $this->manager();
        if ($manager->isBuilt($index)) {
            $manager->unindex($index, $model);
            $this->scheduleVocabularyRefresh($index);
        }
    }

    private function reindexDependents(string $index, string $dependentClass, string $foreignKey, Model $watched): void
    {
        $manager = $this->manager();
        if (! $manager->isBuilt($index)) {
            return;
        }

        $prototype = new $dependentClass;
        $prototype->newQuery()->where($foreignKey, $watched->getKey())
            ->lazyById(200, $prototype->getKeyName())
            ->each(fn (Model $dependent) => $manager->reindex($index, $dependent));

        $this->scheduleVocabularyRefresh($index);
    }

    private function scheduleVocabularyRefresh(string $index): void
    {
        RefreshVocabularyJob::dispatch($index)->delay(now()->addSeconds((int) $this->config->get('pecle_search.sync.vocabulary_delay_seconds', 30)));
    }

    /** Ejecuta cuando se confirma la transacción en curso (o de inmediato si no hay). */
    private function afterCommit(callable $callback): void
    {
        DB::afterCommit(fn () => $this->safely($callback));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function manager(): PecleSearchManager
    {
        return $this->container->make(PecleSearchManager::class);
    }
}
