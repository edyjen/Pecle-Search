<?php

namespace Pecle\Search\Laravel;

use Pecle\Search\Core\Contracts\EntitySource;
use Pecle\Search\Core\Contracts\EntityTexts;
use Illuminate\Database\Eloquent\Model;

/**
 * Entidades sacadas de un modelo: el id es su llave y el texto, sus columnas
 * unidas con espacio. Una columna puede venir de una relación con notación de
 * punto ("fabric.name"); esas relaciones se cargan por adelantado. Respeta los
 * global scopes del modelo (las filas con soft delete no entran).
 */
final class EloquentEntitySource implements EntitySource, EntityTexts
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     */
    public function __construct(
        private readonly string $model,
        private readonly array $columns,
    ) {}

    /** @return class-string<Model> */
    public function model(): string
    {
        return $this->model;
    }

    public function entities(): iterable
    {
        $prototype = new $this->model;

        $rows = $prototype->newQuery()->with($this->relations())->lazyById(500, $prototype->getKeyName());

        foreach ($rows as $row) {
            yield $row->getKey() => $this->textFor($row);
        }
    }

    public function texts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $prototype = new $this->model;
        $texts = [];
        foreach ($prototype->newQuery()->with($this->relations())->whereKey($ids)->get() as $row) {
            $texts[(string) $row->getKey()] = $this->textFor($row);
        }

        return $texts;
    }

    /** El texto por el que se busca una entidad. */
    public function textFor(Model $model): string
    {
        $parts = [];
        foreach ($this->columns as $column) {
            $value = data_get($model, $column);
            if (is_scalar($value) && $value !== '') {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }

    /** @return list<string> relaciones que hay que cargar para leer las columnas con punto */
    private function relations(): array
    {
        $relations = [];
        foreach ($this->columns as $column) {
            if (str_contains($column, '.')) {
                $relations[] = substr($column, 0, (int) strrpos($column, '.'));
            }
        }

        return array_values(array_unique($relations));
    }
}
