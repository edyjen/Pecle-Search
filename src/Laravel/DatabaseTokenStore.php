<?php

namespace Pecle\Search\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Pecle\Search\Core\Contracts\CompletionStore;
use Pecle\Search\Core\Contracts\TokenStore;

/**
 * El índice de búsqueda en la base de datos.
 *
 * Dos tablas de tokens, con la misma forma salvo el tipo de `entity_id`:
 *  - `search_tokens` (texto): la entidad se identifica por ULID/UUID o por un id que se guarda como texto.
 *  - `numeric_search_tokens` (entero): la entidad tiene id numérico. Comparar entero con entero deja que
 *    el motor use la PK del índice; con la tabla de texto y una PK numérica hay que convertir la llave y
 *    el plan se cae (medido: 1 ms contra 15 ms, y sin la conversión, 15 s).
 *
 * La fuente de cada índice declara `numeric_index => true` cuando corresponde (ver
 * `config/pecle_search.php`); `search_indexes.table_name` recuerda en qué tabla está construido, para que
 * un cambio de tabla sin reconstruir no se confunda con "construido".
 */
final class DatabaseTokenStore implements CompletionStore, TokenStore
{
    public const TOKENS = 'search_tokens';

    public const NUMERIC_TOKENS = 'numeric_search_tokens';

    public const INDEXES = 'search_indexes';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
        /** Filas por INSERT (3 columnas cada una: debe quedar bajo el límite de variables del motor). */
        private readonly int $insertChunk = 200,
    ) {}

    /**
     * ¿El índice guarda ids numéricos? Lo dicen sus fuentes con `numeric_index => true`.
     *
     * Todas tienen que coincidir: la tabla y el espacio de `entity_id` son del **índice**, así que uno que
     * mezcle entidades numéricas y de texto no tiene dónde guardarlas de forma coherente. Se rechaza con
     * un mensaje claro; la salida es separarlo en dos índices.
     */
    public function numeric(string $index): bool
    {
        $entradas = (array) $this->config->get("pecle_search.indexes.{$index}", []);
        $tipos = [];
        foreach ($entradas as $entrada) {
            if (is_array($entrada)) {
                $tipos[] = (bool) ($entrada['numeric_index'] ?? false);
            }
        }

        if (in_array(true, $tipos, true) && in_array(false, $tipos, true)) {
            throw new InvalidArgumentException(
                "El índice de búsqueda [{$index}] mezcla entidades con id numérico y con id de texto: ".
                'separalas en dos índices distintos (una fuente por tipo).'
            );
        }

        return ($tipos[0] ?? false) === true;
    }

    /** La tabla de tokens del índice. */
    public function tokensTable(string $index): string
    {
        return $this->numeric($index) ? self::NUMERIC_TOKENS : self::TOKENS;
    }

    public function replace(string $index, string $entityId, array $tokens): void
    {
        $this->db->transaction(function () use ($index, $entityId, $tokens): void {
            $this->delete($index, $entityId);
            $this->insert($index, [$entityId => $tokens]);
        });
    }

    public function remove(string $index, string $entityId): void
    {
        $this->delete($index, $entityId);
    }

    public function replaceAll(string $index, iterable $entities): int
    {
        return $this->db->transaction(function () use ($index, $entities): int {
            // Las dos tablas: si el índice cambió de tipo, no tiene que quedar basura de la anterior.
            foreach ([self::TOKENS, self::NUMERIC_TOKENS] as $tabla) {
                $this->db->table($tabla)->where('index_name', $index)->delete();
            }

            $count = 0;
            $batch = [];
            $rows = 0;
            foreach ($entities as $id => $tokens) {
                $batch[(string) $id] = $tokens;
                $count++;
                $rows += count($tokens);

                if ($rows >= $this->insertChunk) {
                    $this->insert($index, $batch);
                    $batch = [];
                    $rows = 0;
                }
            }
            $this->insert($index, $batch);

            $this->db->table(self::INDEXES)->upsert(
                [[
                    'index_name' => $index,
                    'entities'   => $count,
                    'built_at'   => now(),
                    'table_name' => $this->tokensTable($index),
                ]],
                ['index_name'],
                ['entities', 'built_at', 'table_name'],
            );

            return $count;
        });
    }

    public function frequencies(string $index): array
    {
        return $this->db->table($this->tokensTable($index))
            ->where('index_name', $index)
            ->selectRaw('token, count(*) as entities')
            ->groupBy('token')
            ->pluck('entities', 'token')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function completions(string $index, array $context, string $prefix, int $limit): array
    {
        $tabla = $this->tokensTable($index);

        $query = $this->db->table($tabla.' as t')->where('t.index_name', $index);

        if ($prefix !== '') {
            $query->where('t.token', 'like', $prefix.'%'); // [a-z0-9]: no hay comodines que escapar
        }
        if ($context !== []) {
            $query->whereNotIn('t.token', $context);
        }
        foreach ($context as $word) {
            // La entidad debe tener cada palabra anterior (como comienzo de palabra, igual que al buscar).
            $query->whereExists(function ($sub) use ($tabla, $index, $word): void {
                $sub->selectRaw('1')
                    ->from($tabla.' as c')
                    ->where('c.index_name', $index)
                    ->whereColumn('c.entity_id', 't.entity_id')
                    ->where('c.token', 'like', $word.'%');
            });
        }

        if ($prefix !== '') {
            $query->orderByRaw('case when t.token = ? then 0 else 1 end', [$prefix]);
        }

        return $query->selectRaw('t.token, count(*) as entities, min(t.entity_id) as first_id, max(t.entity_id) as last_id')
            ->groupBy('t.token')
            ->orderByDesc('entities')
            ->orderBy('t.token')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'token' => (string) $row->token,
                'entities' => (int) $row->entities,
                'ids' => array_values(array_unique([(string) $row->first_id, (string) $row->last_id])),
            ])
            ->all();
    }

    public function isBuilt(string $index): bool
    {
        // La marca dice en qué tabla se construyó: si el índice cambió de tipo y todavía no se
        // reconstruyó, no está construido (y la búsqueda usa el respaldo en vez de devolver vacío).
        return $this->db->table(self::INDEXES)
            ->where('index_name', $index)
            ->where('table_name', $this->tokensTable($index))
            ->exists();
    }

    public function forget(string $index): void
    {
        $this->db->transaction(function () use ($index): void {
            foreach ([self::TOKENS, self::NUMERIC_TOKENS] as $tabla) {
                $this->db->table($tabla)->where('index_name', $index)->delete();
            }
            $this->db->table(self::INDEXES)->where('index_name', $index)->delete();
        });
    }

    private function delete(string $index, string $entityId): void
    {
        $this->db->table($this->tokensTable($index))
            ->where('index_name', $index)
            ->where('entity_id', $this->entityId($index, $entityId))
            ->delete();
    }

    /** @param array<string, list<string>> $entities */
    private function insert(string $index, array $entities): void
    {
        $tabla = $this->tokensTable($index);
        $rows = [];
        foreach ($entities as $id => $tokens) {
            $entityId = $this->entityId($index, (string) $id);
            foreach ($tokens as $token) {
                $rows[] = ['index_name' => $index, 'entity_id' => $entityId, 'token' => $token];
            }
        }

        foreach (array_chunk($rows, $this->insertChunk) as $chunk) {
            $this->db->table($tabla)->insert($chunk);
        }
    }

    /**
     * El id como lo guarda la tabla. En un índice numérico se rechaza un id que no lo sea: convertirlo a
     * 0 juntaría todas las entidades de texto en una sola y la búsqueda devolvería cualquier cosa.
     */
    private function entityId(string $index, string $entityId): int|string
    {
        if (! $this->numeric($index)) {
            return $entityId;
        }

        if (! ctype_digit($entityId)) {
            throw new InvalidArgumentException(
                "El índice de búsqueda [{$index}] está declarado numérico (`numeric_index`) pero la entidad ".
                "[{$entityId}] no tiene un id numérico. Revisá la fuente o sacá ese indicador."
            );
        }

        return (int) $entityId;
    }
}
