<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignaturedPattern extends Model
{
    use HasFactory;

    protected $table = 'signatured_patterns';

    protected $fillable = [
        'source_name_pattern_id', 'signatured_pattern',
    ];

    public function sourceNamePattern(): BelongsTo
    {
        return $this->belongsTo(SourceNamePattern::class);
    }
}
