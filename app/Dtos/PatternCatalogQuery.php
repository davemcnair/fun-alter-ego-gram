<?php

namespace App\Dtos;

final readonly class PatternCatalogQuery
{
    public function __construct(
        public string $token = '',
    ) {
    }
}
