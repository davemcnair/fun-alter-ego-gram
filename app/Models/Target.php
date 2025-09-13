<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Target extends Model
{
    use HasRelationships;

    protected $fillable = [
        'name', 'signature', 'normalized_key', 'anagram_signature', 'status',
    ];

    public function patterns(): HasMany
    {
        return $this->hasMany(TargetPattern::class)->with(['pattern']);
    }

    public function signatureIndexedPatterns(): HasManyThrough
    {
        return $this->hasManyThrough(TargetSignatureIndexedPattern::class, TargetPattern::class);
    }

    public function alterEgos(): HasManyDeep
    {
        return $this->hasManyDeep(AlterEgo::class, [TargetPattern::class, TargetSignatureIndexedPattern::class]);
    }

    public function tokenSignatureWords(): BelongsToMany
    {
        return $this->belongsToMany(
            TokenSignatureWord::class,
            'target_token_signature_words',
            'target_id',
            'token_signature_word_id'
        );
    }

    /**
     * Lightweight anagram siblings: other targets with the same anagram_signature
     * Returns collection of [id, name]
     */
    public function anagramSiblings(): \Illuminate\Support\Collection
    {
        if (empty($this->anagram_signature)) return collect();
        return static::query()
            ->where('anagram_signature', $this->anagram_signature)
            ->where('id', '!=', $this->id)
            ->orderBy('id')
            ->get(['id','name']);
    }
    protected static function booted(): void
    {
        static::creating(function (Target $model) {
            // Populate legacy signature if missing
            if (empty($model->signature) && !empty($model->name)) {
                $model->signature = \App\Support\NameNormalizer::canonicalKey($model->name);
            }
            // Populate dual keys if columns exist and not already set
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('targets', 'normalized_key')) {
                    if (empty($model->normalized_key) && !empty($model->name)) {
                        $model->normalized_key = \App\Support\NameNormalizer::canonicalKey($model->name);
                    }
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('targets', 'anagram_signature')) {
                    if (empty($model->anagram_signature) && !empty($model->name)) {
                        $model->anagram_signature = \App\Support\NameNormalizer::anagramSignature($model->name);
                    }
                }
            } catch (\Throwable $e) {
                // ignore schema lookup issues in some test environments
            }
        });
    }
}
