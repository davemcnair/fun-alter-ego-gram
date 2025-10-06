<?php

namespace App\Dtos;

use App\Models\Token;
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
        public int  $usageCount = 0,
    ){}

    public function joinTo(WordDto $prevWord): string
    {
        return match ($prevWord->tokenType)
        {
            Token::TOKEN_NAME_TITLE,
            Token::TOKEN_NAME_INITIALS
                => match($this->tokenType) {
                    Token::TOKEN_NAME_SURNAME => ' '. ucfirst($this->word),
                    default => ' '. $this->word
                },
            Token::TOKEN_NAME_FORENAME
                => match($this->tokenType) {
                    Token::TOKEN_NAME_FORENAME => '-'. $this->word,
                    Token::TOKEN_NAME_SURNAME => ' '. ucfirst($this->word),
                    default => ' '. $this->word
                },
            Token::TOKEN_NAME_PREFIX
                => match($this->tokenType) {
                    Token::TOKEN_NAME_SURNAME => ucfirst($this->word),
                    default => $this->word
                },
            Token::TOKEN_NAME_SURNAME
                => match($this->tokenType) {
                    Token::TOKEN_NAME_SURNAME => '-' . ucfirst($this->word),
                    default => ' '. ucfirst($this->word)
                },
            Token::TOKEN_NAME_SUFFIX
                => match($this->tokenType) {
                    default => ucfirst($this->word)
                },
            default => ' ' . ucfirst($this->word),
        };
    }
}
