<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SourceName extends Model
{

    protected $fillable = [
        'name', 'signature', 'status',
    ];

    public function patterns(): HasMany
    {
        return $this->hasMany(SourceNamePattern::class);
    }

    public function signatureIndexedPatterns(): HasManyThrough
    {
        return $this->hasManyThrough(SignatureIndexedPattern::class, SourceNamePattern::class);
    }

    public function alterEgos(): HasManyThrough
    {
        // HasManyThrough through two layers:
        // todo: examine
        //      $source_name->sourceNamePatterns->flatMap->signatureIndexedPatterns->flatMap->alterEgos
        return $this->hasManyThrough(AlterEgo::class,SignatureIndexedPattern::class)
            ->join(
                'source_name_patterns',
                 'signature_indexed_patterns.source_name_pattern_id',
                '=',
                'source_name_patterns.id'
            )
            ->whereColumn('source_name_patterns.id.source_name_id', 'source_name.id');

    }
}
