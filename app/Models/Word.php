<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{

    protected $table = 'words';

    protected $fillable = [
        'word',
        'token_type',
        'list_type',
        'use_for_search',
        'signature',
        'anagram_group_id',
    ];

    public function matchedWords(): HasMany
    {
        return $this->hasMany(MatchedWord::class);
    }

    public function anagramGroup()
    {
        return $this->belongsTo(AnagramGroup::class);
    }
}
