<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SourceNamePattern extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_name_id', 'pattern_template', 'popularity_rank', 'status'
    ];

    public function sourceName(): BelongsTo
    {
        return $this->belongsTo(SourceName::class);
    }

    public function signaturedPatterns(): HasMany
    {
        return $this->hasMany(SignaturedPattern::class);
    }

    public function alterEgos(): HasManyThrough
    {
        return $this->hasManyThrough(AlterEgo::class, SignaturedPattern::class);
    }
}
