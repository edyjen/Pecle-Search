<?php

namespace Pecle\Search\Core;

/** Un candidato devuelto por el motor, con lo que costó llegar a él. */
final readonly class Hit
{
    public function __construct(
        public int $wordId,
        public string $text,
        public float $cost,
        public int $freq,
        public float $rank,
    ) {}
}
