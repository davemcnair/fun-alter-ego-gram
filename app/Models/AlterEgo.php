<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlterEgo extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_name_id', 'source_name_pattern_id', 'phrase', 'starred'
    ];

    public function sourceName(): BelongsTo
    {
        return $this->belongsTo(SourceName::class);
    }

    public function sourceNamePattern(): BelongsTo
    {
        return $this->belongsTo(SourceNamePattern::class);
    }
}
