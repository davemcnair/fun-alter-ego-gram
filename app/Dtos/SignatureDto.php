<?php

namespace App\Dtos;

use App\Support\NameNormalizer;
use Spatie\LaravelData\Data;

class SignatureDto extends Data
{
    public function __construct(
        public string $signature,
        public array $defaults
    ) {}

    public static function fromWord(string $word): self
    {
        return NameNormalizer::anagramSignature($word);
    }
}
