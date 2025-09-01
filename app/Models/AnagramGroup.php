<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnagramGroup extends Model
{
    use HasFactory;

    protected $table = 'anagram_groups';

    protected $fillable = [
        'token_type',
        'signature',
        'words_count',
    ];

    public function words()
    {
        return $this->hasMany(Word::class);
    }
}
