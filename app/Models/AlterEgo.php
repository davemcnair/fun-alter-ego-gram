<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlterEgo extends Model
{
    protected $fillable = [
        'target_signature_indexed_pattern_id', 'phrase', 'starred'
    ];

    public function targetSignatureIndexedPattern(): BelongsTo
    {
        return $this->belongsTo(TargetSignatureIndexedPattern::class, 'target_signature_indexed_pattern_id');
    }

}
