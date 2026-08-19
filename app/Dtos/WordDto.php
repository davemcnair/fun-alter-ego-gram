<?php

namespace App\Dtos;

use Spatie\LaravelData\Data;

class WordDto extends Data
{
    public function __construct(
        public string $tokenType,
        public string $word,
        public string $listType,
        public bool $isPromotable = false,
        public ?string $id = null, // target_token_signature_word
        public bool $deferred = false,
        public bool $used = false,
        public int $usageCount = 0,
    ) {}
}
