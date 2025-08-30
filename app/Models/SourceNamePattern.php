<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceNamePattern extends Model
{
    use HasFactory;

    public const DEFAULT_MAX_RANK = 100;

    protected $fillable = [
        'source_name_id', 'pattern_template', 'popularity_rank', 'status'
    ];

    public function sourceName(): BelongsTo
    {
        return $this->belongsTo(SourceName::class);
    }

    public function alterEgos(): HasMany
    {
        return $this->hasMany(AlterEgo::class);
    }
}
