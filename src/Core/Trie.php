<?php

namespace Pecle\Search\Core;

final class Trie
{
    public readonly TrieNode $root;

    public function __construct()
    {
        $this->root = new TrieNode;
    }

    public function insert(string $word, int $wordId, int $freq = 0): void
    {
        $node = $this->root;
        foreach (mb_str_split($word) as $char) {
            $node = $node->children[$char] ??= new TrieNode;
        }
        $node->isWord = true;
        $node->wordId = $wordId;
        $node->text = $word;
        $node->freq = $freq;
    }

    /** Nodo del prefijo exacto, o null. */
    public function exactNode(string $prefix): ?TrieNode
    {
        $node = $this->root;
        foreach (mb_str_split($prefix) as $char) {
            $node = $node->children[$char] ?? null;
            if ($node === null) {
                return null;
            }
        }

        return $node;
    }

    /** ¿Es $word una palabra completa del vocabulario? */
    public function hasWord(string $word): bool
    {
        return $this->exactNode($word)?->isWord === true;
    }

    /** Se llama una vez, con todo el vocabulario ya insertado. $k = tamaño del caché por nodo. */
    public function buildTopK(int $k = 8): void
    {
        $this->visit($this->root, $k);
    }

    /** @return list<Candidate> */
    private function visit(TrieNode $node, int $k): array
    {
        $entries = $node->isWord ? [new Candidate($node->wordId, $node->text, $node->freq)] : [];
        foreach ($node->children as $child) {
            array_push($entries, ...$this->visit($child, $k));
        }
        usort($entries, fn (Candidate $a, Candidate $b) => $b->freq <=> $a->freq); // estable: desempata por orden de inserción

        return $node->topK = array_slice($entries, 0, $k);
    }
}
