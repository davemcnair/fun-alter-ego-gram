<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignatureIndexedPattern extends Model
{
    protected $fillable = [
        'source_name_pattern_id', 'pattern',
    ];

    public function sourceNamePattern(): BelongsTo
    {
        return $this->belongsTo(SourceNamePattern::class);
    }

    public function alterEgos(): HasMany
    {
        return $this->hasMany(AlterEgo::class);
    }
}
