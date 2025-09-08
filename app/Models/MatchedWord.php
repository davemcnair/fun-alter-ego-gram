<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchedWord extends Model
{

    protected $fillable = [
        'source_name_id', 'word_id', 'used'
    ];

    public function sourceName(): BelongsTo
    {
        return $this->belongsTo(SourceName::class);
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
