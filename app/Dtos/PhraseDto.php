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
    ){}

    /**
     * @param array<WordDto> $words
     * @return PhraseDto
     */
    public static function fromWords(array $words): PhraseDto
    {
        $isFun = false;
        $hasBoring = false;
        $prevWord = null;
        $phrase = '';
        foreach ($words as $word) {
            if (is_null($prevWord)){
                $phrase = $word->word;
                $prevWord = $word;
                continue;
            }
            $phrase.= $word->joinTo($prevWord);
            $isFun = $isFun || $word->listType === 'fun';
            $hasBoring = $hasBoring || $word->listType === 'boring';
        }
        return new PhraseDto($phrase, $isFun, $hasBoring);
    }
}
