<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class SourceName extends Model
{
    use HasRelationships;

    protected $fillable = [
        'name', 'signature', 'status',
    ];

    public function patterns(): HasMany
    {
        return $this->hasMany(SourceNamePattern::class)->with(['pattern']);
    }

    public function signatureIndexedPatterns(): HasManyThrough
    {
        return $this->hasManyThrough(SignatureIndexedPattern::class, SourceNamePattern::class);
    }

    public function alterEgos(): HasManyDeep
    {
        return $this->hasManyDeep(AlterEgo::class, [SourceNamePattern::class, SignatureIndexedPattern::class]);
    }
}
