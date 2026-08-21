<?php

namespace App\Dtos;

final readonly class PatternCatalogRow
{
    public function __construct(
        public int $id,
        public int $rank,
        public string $type,
        public string $template,
        public string $example,
        public int $minLength,
    ) {
    }
}
