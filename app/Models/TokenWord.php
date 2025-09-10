<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenWord extends Model
{
    protected $fillable = ['signature_id', 'word_original', 'is_deferred'];

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }
}

