<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlterEgo extends Model
{
    protected $fillable = [
        'target_signatured_pattern_id',
        'phrase',
        'starred',
        'isFun',
        'hasBoring',
        'hasDeferred',
    ];

    protected $casts = [
        'starred' => 'boolean',
        'isFun' => 'boolean',
        'hasBoring' => 'boolean',
        'hasDeferred' => 'boolean',
    ];

    public function targetSignaturedPattern(): BelongsTo
    {
        return $this->belongsTo(TargetSignaturedPattern::class);
    }

}
