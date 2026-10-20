<?php

namespace Pecle\Search\Core;

/** Qué pasó con una palabra del query. */
final readonly class TokenResolution
{
    /** Corregida por teclas vecinas o mano corrida (la capa 1). */
    public const KEYBOARD = 'keyboard';

    /** Corregida a la palabra completa con una edición (ver EditCandidate: extra_letter, missing_letter, transposition, shift_slip). */
    public const PREFIX = 'prefix';

    public function __construct(
        public string $original,
        public string $applied,
        /** Costo de edición de la corrección; 0 si la palabra no se tocó. */
        public float $cost = 0.0,
        /** Cómo se corrigió: keyboard | extra_letter | missing_letter | transposition | shift_slip | prefix; null si no se tocó. */
        public ?string $edit = null,
    ) {}

    public function corrected(): bool
    {
        return $this->applied !== $this->original;
    }
}
