<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlterEgo extends Model
{

    protected $fillable = [
        'signature_indexed_pattern_id', 'phrase', 'starred'
    ];

    public function signatureIndexedPattern(): BelongsTo
    {
        return $this->belongsTo(SignatureIndexedPattern::class);
    }
}
