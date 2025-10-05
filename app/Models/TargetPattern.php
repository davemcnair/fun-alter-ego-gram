<?php

namespace App\Models;

use App\Enums\TargetPatternStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TargetPattern extends Model
{

    protected $with = ['target', 'pattern'];

    protected $fillable = [
        'target_id', 'pattern_id', 'popularity_rank', 'status', 'started_at', 'finished_at', 'elapsed_ms'
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'elapsed_ms' => 'integer',
            'status' => TargetPatternStatus::class,
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }

    public function signaturedPatterns(): HasMany
    {
        return $this->hasMany(TargetSignaturedPattern::class);
    }

    public function alterEgos(): HasManyThrough
    {
        return $this->hasManyThrough(AlterEgo::class, TargetSignaturedPattern::class);
    }
}
