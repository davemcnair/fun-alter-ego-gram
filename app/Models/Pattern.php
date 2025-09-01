<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pattern extends Model
{
    use HasFactory;

    protected $fillable = [
        'template',
        'popularity_rank',
        'pattern_type',
        'min_total_length',
        'forename_count',
        'surname_count',
        'has_title',
        'has_initials',
        'has_prefix',
        'has_suffix',
        'has_honorific',
    ];

    public function has(string $tokenType): bool
    {
        return match($tokenType) {
            Token::TOKEN_NAME_TITLE => $this->has_title,
            Token::TOKEN_NAME_FORENAME => $this->forename_count > 0,
            Token::TOKEN_NAME_INITIALS => $this->has_initials,
            Token::TOKEN_NAME_PREFIX => $this->has_prefix,
            Token::TOKEN_NAME_SURNAME => $this->surname_count > 0,
            Token::TOKEN_NAME_SUFFIX => $this->has_suffix,
            Token::TOKEN_NAME_HONORIFIC => $this->has_honorific,
        };
    }
}
