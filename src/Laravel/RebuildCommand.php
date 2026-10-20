<?php

namespace Pecle\Search\Laravel;

use Illuminate\Console\Command;

final class RebuildCommand extends Command
{
    protected $signature = 'pecle-search:rebuild {index?* : Índices a reconstruir (todos si se omite)}';

    protected $description = 'Reconstruye los índices de búsqueda y el vocabulario de autocorrección';

    public function handle(PecleSearchManager $manager): int
    {
        $requested = $this->argument('index');
        $known = $manager->indexes();
        $indexes = $requested === [] ? $known : $requested;

        if ($indexes === []) {
            $this->components->warn('No hay índices configurados (pecle_search.indexes).');

            return self::SUCCESS;
        }

        $unknown = array_diff($indexes, $known);
        if ($unknown !== []) {
            $this->components->error('Índice(s) no configurado(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        foreach ($indexes as $index) {
            $start = hrtime(true);
            $entities = $manager->rebuild($index);
            $this->components->twoColumnDetail(
                $index,
                sprintf('%d entidades, %d ms', $entities, (hrtime(true) - $start) / 1e6),
            );
        }

        return self::SUCCESS;
    }
}
