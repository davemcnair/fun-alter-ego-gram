<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public function alterEgos(): HasManyThrough
    {
        return $this->hasManyThrough(AlterEgo::class, SourceNamePattern::class);
    }
}
