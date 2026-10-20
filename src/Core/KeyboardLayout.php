<?php

namespace Pecle\Search\Core;

/**
 * Geometría de un teclado: qué tan cerca están dos teclas y hacia dónde se
 * corrió el dedo. Las coordenadas son aproximadas (unidades = ancho de tecla),
 * con el desfase real de cada fila.
 *
 * Para otro idioma o distribución se pasa otra lista de filas al constructor.
 */
final class KeyboardLayout
{
    /** @var array<string, array{x: float, y: float}> */
    private array $coords = [];

    /** @var array<string, array<string, float>> costo de sustituir una tecla por otra, solo dentro del radio */
    private array $adjacency = [];

    /**
     * @param  list<array{chars: string, y: float|int, xOffset: float|int}>  $rows
     * @param  float  $alpha  escala del costo de sustitución por distancia
     * @param  float  $radius  corte duro: más lejos que esto la sustitución es imposible
     */
    public function __construct(
        array $rows,
        private readonly float $alpha = 1.6,
        private readonly float $radius = 1.8,
    ) {
        foreach ($rows as $row) {
            foreach (mb_str_split($row['chars']) as $i => $char) {
                $this->coords[$char] = ['x' => (float) ($row['xOffset'] + $i), 'y' => (float) $row['y']];
            }
        }

        foreach ($this->coords as $a => $pa) {
            $near = [];
            foreach ($this->coords as $b => $pb) {
                $distance = hypot($pa['x'] - $pb['x'], $pa['y'] - $pb['y']);
                if ($distance === 0.0) {
                    $near[$b] = 0.0;
                } elseif ($distance <= $this->radius) {
                    $near[$b] = $this->alpha * $distance;
                }
            }
            $this->adjacency[$a] = $near;
        }
    }

    /** QWERTY en español (incluye la ñ). */
    public static function qwertyEs(float $alpha = 1.6, float $radius = 1.8): self
    {
        return new self([
            ['chars' => '1234567890', 'y' => 0, 'xOffset' => 0],
            ['chars' => 'qwertyuiop', 'y' => 1, 'xOffset' => 0.5],
            ['chars' => 'asdfghjklñ', 'y' => 2, 'xOffset' => 0.75],
            ['chars' => 'zxcvbnm', 'y' => 3, 'xOffset' => 1.25],
        ], $alpha, $radius);
    }

    public function alpha(): float
    {
        return $this->alpha;
    }

    /**
     * Lo menos que puede costar una sustitución a una tecla vecina. Una palabra
     * desconocida que se corrige con el motor de sustituciones cuesta al menos
     * esto, así que una alternativa más barata gana sin necesidad de correrlo.
     */
    public function minSubstitutionCost(): float
    {
        $min = INF;
        foreach ($this->adjacency as $near) {
            foreach ($near as $cost) {
                if ($cost > 0.0) {
                    $min = min($min, $cost);
                }
            }
        }

        return $min;
    }

    /**
     * Las teclas dentro del radio de $char (sin contarla), en orden estable.
     *
     * @return list<string>
     */
    public function neighbors(string $char): array
    {
        $neighbors = [];
        foreach ($this->adjacency[$char] ?? [] as $other => $cost) {
            if ($cost > 0.0) {
                $neighbors[] = (string) $other; // PHP vuelve int las llaves "7"
            }
        }

        return $neighbors;
    }

    /** La tecla $columns lugares a la derecha (o a la izquierda, si es negativo) en la misma fila, o null. */
    public function shifted(string $char, int $columns): ?string
    {
        $from = $this->coords[$char] ?? null;
        if ($from === null) {
            return null;
        }

        foreach ($this->coords as $other => $to) {
            if ($to['y'] === $from['y'] && abs($to['x'] - ($from['x'] + $columns)) < 1e-9) {
                return (string) $other;
            }
        }

        return null;
    }

    /** Costo de haber tecleado $typed queriendo $wanted; INF si no son vecinas o no están en el teclado. */
    public function substitutionCost(string $typed, string $wanted): float
    {
        return $this->adjacency[$typed][$wanted] ?? INF;
    }

    /**
     * Dónde cayó el dedo ($typed) respecto de la tecla buscada ($wanted).
     * Dos errores seguidos con el mismo vector son la MISMA deriva de la mano.
     *
     * @return array{dx: float, dy: float}|null null si alguna tecla no está en el teclado
     */
    public function errorVector(string $typed, string $wanted): ?array
    {
        if (! isset($this->coords[$typed], $this->coords[$wanted])) {
            return null;
        }

        return [
            'dx' => $this->coords[$typed]['x'] - $this->coords[$wanted]['x'],
            'dy' => $this->coords[$typed]['y'] - $this->coords[$wanted]['y'],
        ];
    }
}
