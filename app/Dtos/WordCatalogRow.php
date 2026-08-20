<?php

namespace App\Dtos;

final readonly class WordCatalogRow
{
    /**
     * @param list<array{id: int, word: string}> $anagrams
     */
    public function __construct(
        public int $id,
        public string $word,
        public string $token,
        public string $list,
        public bool $deferred,
        public bool $uncommitted,
        public array $anagrams,
    ) {
    }
}
