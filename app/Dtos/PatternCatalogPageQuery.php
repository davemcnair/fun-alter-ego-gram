<?php

namespace App\Dtos;

final readonly class PatternCatalogPageQuery
{
    public function __construct(
        public string $like = '',
        public int $perPage = 20,
        public int $page = 1,
    ) {
    }
}
