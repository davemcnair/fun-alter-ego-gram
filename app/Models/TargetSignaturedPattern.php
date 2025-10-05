<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TargetSignaturedPattern extends Model
{
    protected $fillable = [
        'target_pattern_id',
    ];

    public function targetPattern(): BelongsTo
    {
        return $this->belongsTo(TargetPattern::class);
    }

    public function targetTokenSignatures(): BelongsToMany
    {
        return $this->belongsToMany(
            TargetTokenSignature::class,
            'target_signatured_pattern_target_token_signature',
            'target_signatured_pattern_id',
            'target_token_signature_id'
        )
            ->using(TargetSignaturedPatternTargetTokenSignature::class) // ADD THIS
            ->withPivot('position')
            ->orderBy('target_signatured_pattern_target_token_signature.position');
    }

    public function alterEgos(): HasMany
    {
        return $this->hasMany(AlterEgo::class);
    }
}
