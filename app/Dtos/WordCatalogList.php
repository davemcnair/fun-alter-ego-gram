<?php

namespace App\Dtos;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class WordCatalogList
{
    /**
     * @param LengthAwarePaginator<int, WordCatalogRow> $items
     * @param list<string> $tokenOptions
     * @param list<string> $listOptions
     */
    public function __construct(
        public LengthAwarePaginator $items,
        public array $tokenOptions,
        public array $listOptions,
        public bool $hasUncommitted,
    ) {
    }
}
