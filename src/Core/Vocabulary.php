<?php

namespace Pecle\Search\Core;

/**
 * Las palabras de un índice con su frecuencia, en orden estable: lo que se
 * guarda y se intercambia. El trie se arma a partir de esto (es más barato
 * reconstruirlo que deserializarlo) y no se persiste.
 *
 * El orden importa: el id de cada palabra es su posición (1-based) y el trie
 * desempata por orden de inserción.
 */
final class Vocabulary
{
    private ?string $version = null;

    /** @param list<array{0: string, 1: int}> $words [palabra, frecuencia] */
    public function __construct(public readonly array $words) {}

    /**
     * El vocabulario del corrector a partir de las frecuencias del índice:
     * solo palabras de letras (los códigos y medidas no se corrigen), de al
     * menos 2 caracteres, ordenadas por frecuencia descendente y luego
     * alfabéticamente.
     *
     * @param  array<string|int, int>  $frequencies  token => nº de entidades
     */
    public static function fromFrequencies(array $frequencies): self
    {
        $words = [];
        foreach ($frequencies as $token => $freq) {
            $token = (string) $token;
            if (preg_match('/^[a-z]{2,}$/', $token)) {
                $words[] = [$token, (int) $freq];
            }
        }
        usort($words, fn (array $a, array $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));

        return new self($words);
    }

    public function count(): int
    {
        return count($this->words);
    }

    /** Huella del contenido: cambia si cambia cualquier palabra o frecuencia. */
    public function version(): string
    {
        return $this->version ??= substr(sha1($this->toJson()), 0, 16);
    }

    public function toTrie(int $topK = 24): Trie
    {
        $trie = new Trie;
        foreach ($this->words as $i => [$word, $freq]) {
            $trie->insert($word, $i + 1, $freq);
        }
        $trie->buildTopK($topK);

        return $trie;
    }

    public function toJson(): string
    {
        return json_encode($this->words, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** null si el contenido no tiene la forma esperada (caché corrupta o de otra versión). */
    public static function fromJson(string $json): ?self
    {
        $words = json_decode($json, true);
        if (! is_array($words)) {
            return null;
        }
        foreach ($words as $entry) {
            if (! is_array($entry) || count($entry) !== 2 || ! is_string($entry[0]) || ! is_int($entry[1])) {
                return null;
            }
        }

        return new self(array_values($words));
    }
}
