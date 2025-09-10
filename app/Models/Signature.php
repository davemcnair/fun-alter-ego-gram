<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Signature extends Model
{
    protected $fillable = ['token_id', 'signature'];

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    public function words(): HasMany
    {
        return $this->hasMany(TokenWord::class);
    }
}

