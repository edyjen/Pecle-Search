<?php

namespace Pecle\Search\Core;

/** Resultado de pasar un query por el corrector. */
final readonly class Resolution
{
    /** @param list<TokenResolution> $tokens solo las palabras (no los espacios); una palabra partida en dos (letter_for_space) es un solo token */
    public function __construct(
        /** Lo que escribió el usuario. */
        public string $original,
        /** Lo que hay que buscar: el original con las palabras corregidas. */
        public string $applied,
        public array $tokens = [],
    ) {}

    /** El query pasa tal cual (corrector apagado, vacío, nada que corregir). */
    public static function unchanged(string $query): self
    {
        return new self($query, $query);
    }

    public function corrected(): bool
    {
        return $this->applied !== $this->original;
    }

    /**
     * Para incluir en una respuesta de API: lo escrito, lo buscado y si difieren.
     *
     * @return array{query: string, applied: string, corrected: bool}
     */
    public function toArray(): array
    {
        return ['query' => $this->original, 'applied' => $this->applied, 'corrected' => $this->corrected()];
    }
}
