<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TargetSignaturedPatternTargetTokenSignature extends Pivot
{
    protected $table = 'target_signatured_pattern_target_token_signature';

    protected $fillable = [
        'target_signatured_pattern_id',
        'target_token_signature_id',
        'position',
    ];

    public $incrementing = true;
}
