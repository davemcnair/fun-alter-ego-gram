<?php

namespace App\Dtos;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class PatternCatalogPage
{
    /**
     * @param LengthAwarePaginator<int, PatternCatalogPageRow> $items
     */
    public function __construct(
        public LengthAwarePaginator $items,
    ) {
    }
}
