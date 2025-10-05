<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TokenSignature extends Model
{
    protected $with = ['token'];

    protected $fillable = [
        'token_id',
        'signature_id',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    public function words(): HasMany
    {
        return $this->hasMany(TokenSignatureWord::class);
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }
}

