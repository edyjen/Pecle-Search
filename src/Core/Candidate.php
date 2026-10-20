<?php

namespace Pecle\Search\Core;

/** Una palabra del vocabulario tal como se guarda en el top-K de cada nodo del trie. */
final readonly class Candidate
{
    public function __construct(
        public int $wordId,
        public string $text,
        public int $freq,
    ) {}
}
