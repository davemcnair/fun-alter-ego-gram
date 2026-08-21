<?php

namespace App\Dtos;

final readonly class TargetProgressRow
{
    public function __construct(
        public int $id,
        public string $name,
        public int $patternsFilled,
        public int $patternsTotal,
        public int $alterEgosCount,
        public int $filledMatches,
        public int $newMatches,
        public ?string $lastProcessed,
        public int $unseenMatches,
    ) {
    }
}
