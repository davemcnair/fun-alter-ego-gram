<?php

namespace App\Dtos;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class TargetProgressList
{
    /**
     * @param LengthAwarePaginator<int, TargetProgressRow> $items
     */
    public function __construct(
        public LengthAwarePaginator $items,
    ) {
    }
}
