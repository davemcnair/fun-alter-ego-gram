<?php

namespace App\Dtos;

use Spatie\LaravelData\Data;

class PhraseDto extends Data
{
    public function __construct(
        public string $phrase,
        public bool $isFun = false,
        public bool $hasBoring = false,
        public bool $starred = false,
        public ?int $id = null,
    ){}

    public static function fromWords(array $words): self
    {
        $phrase = implode(' ', array_map(fn($w) => $w->word, $words));
        $isFun = collect($words)->every(fn($w) => $w->listType === 'fun');
        $hasBoring = collect($words)->contains(fn($w) => $w->listType === 'boring');

        return new self($phrase, $isFun, $hasBoring);
    }
}
