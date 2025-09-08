<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function anagramGroup()
    {
        return $this->belongsTo(AnagramGroup::class);
    }
}
