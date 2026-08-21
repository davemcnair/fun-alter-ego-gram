<?php

namespace App\Dtos;

final readonly class TargetProgressQuery
{
    public function __construct(
        public int $perPage = 25,
        public int $page = 1,
    ) {
    }
}
