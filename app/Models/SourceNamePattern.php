<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SourceNamePattern extends Model
{

    protected $fillable = [
        'source_name_id', 'pattern_id', 'popularity_rank', 'status'
    ];

    public function sourceName(): BelongsTo
    {
        return $this->belongsTo(SourceName::class);
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }

    public function signatureIndexedPatterns(): HasMany
    {
        return $this->hasMany(SignatureIndexedPattern::class);
    }

    public function alterEgos(): HasManyThrough
    {
        return $this->hasManyThrough(AlterEgo::class, SignatureIndexedPattern::class);
    }
}
