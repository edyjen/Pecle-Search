<?php

namespace Pecle\Search\Core;

final class TrieNode
{
    /** @var array<string|int, TrieNode> carácter => hijo, en orden de inserción (PHP vuelve int las llaves "7") */
    public array $children = [];

    public bool $isWord = false;

    public ?int $wordId = null;

    public ?string $text = null;

    public int $freq = 0;

    /** @var list<Candidate>|null las palabras más frecuentes bajo este nodo; lo llena Trie::buildTopK() */
    public ?array $topK = null;
}
