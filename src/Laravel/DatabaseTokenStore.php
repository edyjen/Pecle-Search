<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Contracts\CompletionStore;
use Pecle\Search\Core\Contracts\TokenStore;
use Illuminate\Database\ConnectionInterface;

/** El índice de búsqueda en la base de datos (tablas search_tokens y search_indexes). */
final class DatabaseTokenStore implements CompletionStore, TokenStore
{
    public const TOKENS = 'search_tokens';

    public const INDEXES = 'search_indexes';

    public function __construct(
        private readonly ConnectionInterface $db,
        /** Filas por INSERT (3 columnas cada una: debe quedar bajo el límite de variables del motor). */
        private readonly int $insertChunk = 200,
    ) {}

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
            $this->db->table(self::TOKENS)->where('index_name', $index)->delete();

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
                [['index_name' => $index, 'entities' => $count, 'built_at' => now()]],
                ['index_name'],
                ['entities', 'built_at'],
            );

            return $count;
        });
    }

    public function frequencies(string $index): array
    {
        return $this->db->table(self::TOKENS)
            ->where('index_name', $index)
            ->selectRaw('token, count(*) as entities')
            ->groupBy('token')
            ->pluck('entities', 'token')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function completions(string $index, array $context, string $prefix, int $limit): array
    {
        $query = $this->db->table(self::TOKENS.' as t')->where('t.index_name', $index);

        if ($prefix !== '') {
            $query->where('t.token', 'like', $prefix.'%'); // [a-z0-9]: no hay comodines que escapar
        }
        if ($context !== []) {
            $query->whereNotIn('t.token', $context);
        }
        foreach ($context as $word) {
            // La entidad debe tener cada palabra anterior (como comienzo de palabra, igual que al buscar).
            $query->whereExists(function ($sub) use ($index, $word): void {
                $sub->selectRaw('1')
                    ->from(self::TOKENS.' as c')
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
        return $this->db->table(self::INDEXES)->where('index_name', $index)->exists();
    }

    public function forget(string $index): void
    {
        $this->db->transaction(function () use ($index): void {
            $this->db->table(self::TOKENS)->where('index_name', $index)->delete();
            $this->db->table(self::INDEXES)->where('index_name', $index)->delete();
        });
    }

    private function delete(string $index, string $entityId): void
    {
        $this->db->table(self::TOKENS)->where('index_name', $index)->where('entity_id', $entityId)->delete();
    }

    /** @param array<string, list<string>> $entities */
    private function insert(string $index, array $entities): void
    {
        $rows = [];
        foreach ($entities as $id => $tokens) {
            foreach ($tokens as $token) {
                $rows[] = ['index_name' => $index, 'entity_id' => (string) $id, 'token' => $token];
            }
        }

        foreach (array_chunk($rows, $this->insertChunk) as $chunk) {
            $this->db->table(self::TOKENS)->insert($chunk);
        }
    }
}
