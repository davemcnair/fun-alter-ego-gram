<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenSignatureWord extends Model
{
    protected $with = ['tokenSignature'];

    // Allow both canonical DB column names and the alternative keys used by import code
    protected $fillable = [
        'token_signature_id',
        'list_type',
        'word',
        'is_deferred',
        'committed_at',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if ($model->isDirty('list_type')) {
                // Any change to list type should mark as uncommitted
                $model->committed_at = null;
            }
        });
    }

    public function tokenSignature(): BelongsTo
    {
        return $this->belongsTo(TokenSignature::class);
    }
}

