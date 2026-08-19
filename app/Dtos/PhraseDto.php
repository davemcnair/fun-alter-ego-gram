<?php

namespace App\Dtos;

use App\Models\Token;
use Spatie\LaravelData\Data;

class PhraseDto extends Data
{
    public function __construct(
        public string $phrase,
        public bool $isFun = false,
        public bool $hasBoring = false,
        public bool $hasDeferred = false,
        public bool $starred = false,
        public ?int $id = null,
    ) {}

    /**
     * @param list<WordDto> $words ordered slot words
     */
    public static function fromWords(array $words): self
    {
        $parts = [];
        $n = count($words);
        $i = 0;
        while ($i < $n) {
            $type = strtolower($words[$i]->tokenType);

            if ($type === Token::TOKEN_NAME_FORENAME || $type === Token::TOKEN_NAME_SURNAME) {
                $run = [];
                while ($i < $n && strtolower($words[$i]->tokenType) === $type) {
                    $run[] = self::titleCaseWord($words[$i]->word);
                    $i++;
                }
                $parts[] = implode('-', $run);
                continue;
            }

            if ($type === Token::TOKEN_NAME_PREFIX) {
                $prefix = $words[$i]->word;
                $i++;
                if ($i < $n && strtolower($words[$i]->tokenType) === Token::TOKEN_NAME_SURNAME) {
                    $run = [];
                    while ($i < $n && strtolower($words[$i]->tokenType) === Token::TOKEN_NAME_SURNAME) {
                        $run[] = self::titleCaseWord($words[$i]->word);
                        $i++;
                    }
                    $parts[] = $prefix.implode('-', $run);
                    continue;
                }
                $parts[] = $prefix;
                continue;
            }

            $parts[] = $words[$i]->word;
            $i++;
        }

        $phrase = trim(implode(' ', $parts));
        $isFun = collect($words)->contains(fn (WordDto $w) => $w->listType === 'fun');
        $hasBoring = collect($words)->contains(fn (WordDto $w) => $w->listType === 'boring');
        $hasDeferred = collect($words)->contains(fn (WordDto $w) => $w->deferred);

        return new self($phrase, $isFun, $hasBoring, $hasDeferred);
    }

    private static function titleCaseWord(string $w): string
    {
        if ($w === '') {
            return $w;
        }
        $lw = strtolower($w);
        $len = strlen($lw);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $lw[$i];
            if ($i === 0) {
                $out .= strtoupper($ch);
            } else {
                $prev = $lw[$i - 1];
                $out .= ($prev === "'" || $prev === '-') ? strtoupper($ch) : $ch;
            }
        }
        return $out;
    }
}
