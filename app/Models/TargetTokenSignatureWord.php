<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetTokenSignatureWord extends Model
{
    protected $fillable = [
        'target_id',
        'token_signature_word_id',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function tokenSignatureWord(): BelongsTo
    {
        return $this->belongsTo(TokenSignatureWord::class);
    }

}
