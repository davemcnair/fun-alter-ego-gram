<?php

namespace App\Dtos;

use Illuminate\Support\Collection;

final readonly class PatternCatalogList
{
    /**
     * @param Collection<int, PatternCatalogRow> $items
     * @param array<string, string> $tokenOptions value => label
     */
    public function __construct(
        public Collection $items,
        public array $tokenOptions,
    ) {
    }
}
