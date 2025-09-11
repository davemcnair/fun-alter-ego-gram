<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenSignatureWord extends Model
{
    // Allow both canonical DB column names and the alternative keys used by import code
    protected $fillable = [
        'token_signature_id',
        'list_type',
        'word',
        'is_deferred',
    ];

    public function tokenSignature(): BelongsTo
    {
        return $this->belongsTo(TokenSignature::class);
    }
}

