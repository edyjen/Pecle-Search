<?php

namespace Pecle\Search\Core;

/** @internal Una rama activa del motor: dónde está en el trie, su deriva y lo que lleva gastado. */
final readonly class FrontierState
{
    /** @param array{dx: float, dy: float}|null $drift */
    public function __construct(
        public TrieNode $node,
        public ?array $drift,
        public float $cost,
    ) {}
}
