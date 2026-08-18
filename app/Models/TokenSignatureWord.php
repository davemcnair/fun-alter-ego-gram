<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'is_deferred' => 'boolean',
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

    protected function uncommitted(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->committed_at < $this->updated_at
        );
    }

    protected function isPromotable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->list_type === 'ok'
                && in_array($this->tokenSignature->token->name, [
                        Token::TOKEN_NAME_FORENAME,
                        Token::TOKEN_NAME_SURNAME
                    ], true)
        );
    }
}

