<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Evaluation\CorrectorEvaluator;
use Pecle\Search\Core\Evaluation\TypoGenerator;
use Pecle\Search\Core\Indexer;
use Pecle\Search\Core\KeyboardLayout;
use Pecle\Search\Core\Normalizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Mide el corrector con el vocabulario real de un índice (ver
 * CorrectorEvaluator) para calibrar el tope de costo y el margen de ambigüedad
 * con datos, no a ojo, y para ver lo que aporta (y cuesta) la etapa 2. Sirve
 * para volver a calibrar cuando cambie el catálogo o cuando haya errores de
 * tecleo reales que contrastar.
 */
final class EvaluateCommand extends Command
{
    protected $signature = 'pecle-search:evaluate {index : Índice a medir}
        {--sample=400 : Palabras con las que se fabrican los errores}
        {--seed=1 : Semilla (misma semilla, mismos errores)}
        {--edits=both : on, off o both: con la etapa 2 (una letra de más/menos o cruzadas), sin ella, o las dos lado a lado}';

    protected $description = 'Mide el corrector de búsquedas con el vocabulario real y compara topes de costo, márgenes y la etapa 2';

    /** Topes que se comparan además del configurado: [costo por letra, mínimo]. */
    private const COST_SWEEP = [[0.5, 2.0], [0.6, 2.0], [0.75, 2.0], [0.9, 2.0], [1.0, 2.0], [1.25, 2.0], [1.5, 2.0]];

    /** Márgenes de ambigüedad que se comparan con el tope configurado. */
    private const MARGIN_SWEEP = [0.0, 0.5, 1.0, 1.5, 2.0, 3.0];

    public function handle(Indexer $indexer, KeyboardLayout $layout, Normalizer $normalizer, Config $config): int
    {
        $index = (string) $this->argument('index');
        if (! array_key_exists($index, (array) $config->get('pecle_search.indexes', []))) {
            $this->components->error("El índice [{$index}] no está configurado.");

            return self::FAILURE;
        }
        if (! $indexer->isBuilt($index)) {
            $this->components->error("El índice [{$index}] no está construido: corre pecle-search:rebuild.");

            return self::FAILURE;
        }

        $mode = (string) $this->option('edits');
        if (! in_array($mode, ['on', 'off', 'both'], true)) {
            $this->components->error('--edits debe ser on, off o both.');

            return self::FAILURE;
        }
        $editModes = match ($mode) {
            'on' => [true],
            'off' => [false],
            default => [false, true],
        };

        $current = [
            (float) $config->get('pecle_search.corrector.cost_per_char', 1.0),
            (float) $config->get('pecle_search.corrector.min_cost', 2.0),
            (float) $config->get('pecle_search.corrector.min_margin', 0.0),
            (bool) $config->get('pecle_search.edits.enabled', true),
        ];

        // [costo por letra, mínimo, margen, etapa 2]: el barrido de topes con cada modo, y el de márgenes con el modo configurado.
        $configs = [];
        foreach ([...self::COST_SWEEP, [$current[0], $current[1]]] as [$perChar, $min]) {
            foreach ($editModes as $edits) {
                $configs[implode('|', [$perChar, $min, $current[2], (int) $edits])] = [$perChar, $min, $current[2], $edits];
            }
        }
        $marginMode = in_array($current[3], $editModes, true) ? $current[3] : $editModes[0];
        foreach ([...self::MARGIN_SWEEP, $current[2]] as $margin) {
            $configs[implode('|', [$current[0], $current[1], $margin, (int) $marginMode])] = [$current[0], $current[1], $margin, $marginMode];
        }
        $configs = array_values($configs);
        usort($configs, fn (array $a, array $b) => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);

        $report = (new CorrectorEvaluator($layout, $normalizer))->evaluate(
            $indexer->vocabulary($index),
            array_map(fn (array $c) => ['costPerChar' => $c[0], 'minCost' => $c[1], 'minMargin' => $c[2], 'edits' => $c[3]], $configs),
            (int) $this->option('sample'),
            (int) $this->option('seed'),
        );

        $this->components->info(sprintf(
            'Índice «%s»: %d palabras medibles (4+ letras); muestra de %d, de las que %d se sacan del vocabulario para simular palabras desconocidas.',
            $index, $report['words'], $report['sample'], $report['holdout'],
        ));

        $other = [...TypoGenerator::OTHER_KINDS, ...TypoGenerator::EDIT_KINDS];
        $isCurrent = fn (array $r) => [$r['costPerChar'], $r['minCost'], $r['minMargin'], $r['edits']] === [$current[0], $current[1], $current[2], $current[3]];

        $rows = [];
        foreach ($report['configs'] as $result) {
            $keyboard = $this->sum($result['kinds'], TypoGenerator::KEYBOARD_KINDS);
            $edit = $this->sum($result['kinds'], $other);

            $rows[] = [
                ($isCurrent($result) ? '▶ ' : '  ').sprintf('%.2f × letras (mín. %.1f), margen %.1f, %s', $result['costPerChar'], $result['minCost'], $result['minMargin'], $result['edits'] ? 'con etapa 2' : 'sin etapa 2'),
                $this->percent($keyboard['correct'], $keyboard['cases']),
                $this->percent($keyboard['wrong'], $keyboard['cases']),
                $this->percent($edit['correct'], $edit['cases']),
                $this->percent($edit['wrong'], $edit['cases']),
                $this->percent($result['holdout']['changed'], $result['holdout']['cases']),
                sprintf('%d', $result['avgMicroseconds']),
            ];
        }

        $this->table(
            ['Configuración (▶ = la actual)', 'Teclas: bien', 'Teclas: mal', 'Letra de más/menos y cruzadas: bien', '…: mal', 'Palabras legítimas cambiadas', 'µs/consulta'],
            $rows,
        );

        $this->newLine();
        $this->line('Detalle de la configuración actual:');
        $detail = collect($report['configs'])->first($isCurrent) ?? $report['configs'][0];
        $this->table(
            ['Error', 'Casos', 'Corrige bien', 'Corrige mal', 'No toca'],
            collect($detail['kinds'])->map(fn (array $k, string $kind) => [
                $kind, $k['cases'], $this->percent($k['correct'], $k['cases']), $this->percent($k['wrong'], $k['cases']), $this->percent($k['cases'] - $k['corrected'], $k['cases']),
            ])->values()->all(),
        );

        $this->line('Teclas = sustitución, sustitución doble y mano corrida (la capa 1). Letra de más/menos y cruzadas = omisión, inserción, transposición, letra repetida, tecla vecina de más y letra doble con una menos (la etapa 2; sin ella no se corrigen).');
        $this->line('«Palabras legítimas cambiadas» = palabras bien escritas que no están en el índice y el corrector convirtió en otra: el riesgo real.');
        $this->line('Margen = ventaja mínima del mejor candidato de la capa 1 sobre otro distinto; con menos, la corrección es ambigua y no se aplica (0 = no se exige).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{cases: int, corrected: int, correct: int, wrong: int}>  $kinds
     * @param  list<string>  $names
     * @return array{cases: int, corrected: int, correct: int, wrong: int}
     */
    private function sum(array $kinds, array $names): array
    {
        $total = ['cases' => 0, 'corrected' => 0, 'correct' => 0, 'wrong' => 0];
        foreach ($names as $name) {
            foreach ($kinds[$name] ?? [] as $key => $value) {
                $total[$key] += $value;
            }
        }

        return $total;
    }

    private function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '—' : sprintf('%.1f %%', 100 * $part / $whole);
    }
}
