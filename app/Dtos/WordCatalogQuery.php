<?php

namespace App\Dtos;

final readonly class WordCatalogQuery
{
    public function __construct(
        public string $q = '',
        public bool $exact = false,
        public string $token = '',
        public string $list = '',
        public bool $hasAnagrams = false,
        public int $perPage = 25,
        public int $page = 1,
    ) {
    }
}
