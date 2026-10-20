<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Completer;
use Pecle\Search\Core\Contracts\CompletionStore;
use Pecle\Search\Core\Contracts\IndexStore;
use Pecle\Search\Core\Contracts\TokenStore;
use Pecle\Search\Core\EditCosts;
use Pecle\Search\Core\EditStage;
use Pecle\Search\Core\Indexer;
use Pecle\Search\Core\IndexRegistry;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\SearchEngine;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el módulo. Para usarlo en un proyecto: agregar este provider (en
 * Laravel 11+, bootstrap/providers.php) y, opcionalmente, publicar la
 * configuración con `php artisan vendor:publish --tag=pecle-search-config`.
 */
final class PecleSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/pecle_search.php', 'pecle_search');

        $this->app->singleton(Normalizer::class);

        $this->app->singleton(KeyboardLayout::class, fn (Application $app) => KeyboardLayout::qwertyEs(
            (float) $app['config']->get('pecle_search.engine.alpha'),
            (float) $app['config']->get('pecle_search.engine.radius'),
        ));

        $this->app->singleton(SearchEngine::class, fn (Application $app) => new SearchEngine(
            $app->make(KeyboardLayout::class),
            $app->make(Normalizer::class),
            (int) $app['config']->get('pecle_search.engine.narrow_depth'),
            (int) $app['config']->get('pecle_search.engine.beam_width'),
            (int) $app['config']->get('pecle_search.engine.max_results'),
            (float) $app['config']->get('pecle_search.engine.freq_gamma'),
            (float) $app['config']->get('pecle_search.engine.completeness_weight'),
        ));

        $this->app->singleton(EditCosts::class, fn (Application $app) => new EditCosts(
            $app->make(KeyboardLayout::class),
            (float) $app['config']->get('pecle_search.edits.duplicate'),
            (float) $app['config']->get('pecle_search.edits.fat_finger'),
            (float) $app['config']->get('pecle_search.edits.extra'),
            (float) $app['config']->get('pecle_search.edits.double_omission'),
            (float) $app['config']->get('pecle_search.edits.omission'),
            (float) $app['config']->get('pecle_search.edits.transposition'),
            (float) $app['config']->get('pecle_search.edits.first_letter_penalty'),
            (float) $app['config']->get('pecle_search.edits.last_letter_penalty'),
            (float) $app['config']->get('pecle_search.edits.shift_slip'),
            (float) $app['config']->get('pecle_search.edits.shift_slip_near'),
        ));

        $this->app->singleton(EditStage::class, fn (Application $app) => new EditStage(
            $app->make(EditCosts::class),
            false,
            (float) $app['config']->get('pecle_search.engine.freq_gamma'),
            (float) $app['config']->get('pecle_search.engine.completeness_weight'),
        ));

        $this->app->singleton(IndexStore::class, fn (Application $app) => new CacheIndexStore(
            $app['cache']->store($app['config']->get('pecle_search.cache.store')),
            (string) $app['config']->get('pecle_search.cache.prefix'),
        ));

        $this->app->singleton(IndexRegistry::class, fn (Application $app) => new IndexRegistry(
            $app->make(IndexStore::class),
            (int) $app['config']->get('pecle_search.engine.top_k'),
        ));

        $this->app->singleton(TokenStore::class, fn (Application $app) => new DatabaseTokenStore($app['db']->connection()));
        $this->app->singleton(CompletionStore::class, fn (Application $app) => $app->make(TokenStore::class));
        $this->app->singleton(Completer::class, fn (Application $app) => new Completer(
            $app->make(CompletionStore::class),
            $app->make(Normalizer::class),
            (int) $app['config']->get('pecle_search.suggest.context_words'),
            (int) $app['config']->get('pecle_search.suggest.min_corrected_length'),
        ));
        $this->app->singleton(Indexer::class);
        $this->app->singleton(IndexedSearch::class);
        $this->app->singleton(PecleSearchManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->app->make(IndexSynchronizer::class)->register();

        // Fabric::query()->whereIndexed('fabrics', $term, ['name', 'code'])
        EloquentBuilder::macro('whereIndexed', function (string $index, string $term, array|Closure $fallback = []) {
            /** @var EloquentBuilder $this */
            return app(IndexedSearch::class)->apply($this, $index, $term, $fallback);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class, EvaluateCommand::class]);
            $this->publishes([__DIR__.'/../../config/pecle_search.php' => config_path('pecle_search.php')], 'pecle-search-config');
        }
    }
}
