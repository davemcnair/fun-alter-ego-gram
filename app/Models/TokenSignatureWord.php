<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenSignatureWord extends Model
{
    protected $fillable = ['signature_id', 'list_type', 'word', 'is_deferred'];

    public function tokenSignature(): BelongsTo
    {
        return $this->belongsTo(TokenSignature::class);
    }
}

