<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlterEgo extends Model
{
    use HasFactory;

    protected $fillable = [
        'signatured_pattern_id', 'phrase', 'starred'
    ];

    public function signaturedPattern(): BelongsTo
    {
        return $this->belongsTo(SignaturedPattern::class);
    }
}
