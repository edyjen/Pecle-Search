<?php

namespace Pecle\Search\Core;

/** Una palabra del vocabulario a una edición de lo tecleado, con lo que cuesta llegar a ella. */
final readonly class EditCandidate
{
    public const EXTRA_LETTER = 'extra_letter';

    public const MISSING_LETTER = 'missing_letter';

    public const TRANSPOSITION = 'transposition';

    /** Falta una letra y en su lugar se pulsó Shift o Mayús (queda una mayúscula a media palabra): "lNa", "lNA". */
    public const SHIFT_SLIP = 'shift_slip';

    public function __construct(
        /** Lo que se aplica: la palabra completa (o, en modo prefijo, el tramo corregido). */
        public string $text,
        public float $cost,
        public int $freq,
        public float $rank,
        /** extra_letter | missing_letter | transposition | shift_slip */
        public string $kind,
        /** false si aterrizó en el comienzo de una palabra y no en una palabra completa (solo modo prefijo). */
        public bool $isWord = true,
    ) {}
}
