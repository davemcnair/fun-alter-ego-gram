<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TargetSignatureIndexedPattern extends Model
{
    protected $fillable = [
        'target_pattern_id', 'pattern',
    ];

    public function targetPattern(): BelongsTo
    {
        return $this->belongsTo(TargetPattern::class);
    }

    public function alterEgos(): HasMany
    {
        return $this->hasMany(AlterEgo::class);
    }
}
