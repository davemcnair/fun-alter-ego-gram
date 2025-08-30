<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceName extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'signature', 'status',
        'patterns_total', 'patterns_searched', 'alteregos_found',
        'current_pattern', 'elapsed_seconds',
        'started_at', 'paused_at', 'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function patterns(): HasMany
    {
        return $this->hasMany(SourceNamePattern::class);
    }

    public function alterEgos(): HasMany
    {
        return $this->hasMany(AlterEgo::class);
    }
}
