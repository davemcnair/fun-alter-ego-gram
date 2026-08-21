<?php

namespace App\Dtos;

final readonly class PatternCatalogPageRow
{
    public function __construct(
        public int $rank,
        public string $template,
        public int $minLength,
    ) {
    }
}
