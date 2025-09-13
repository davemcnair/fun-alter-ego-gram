<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AlterEgo extends Model
{

    protected $fillable = [
        'signature_indexed_pattern_id', 'phrase', 'starred'
    ];

    public function signatureIndexedPattern(): BelongsTo
    {
        return $this->belongsTo(TargetSignatureIndexedPattern::class);
    }

    // Computed attributes to expose denormalized relations without DB columns
    public function getTargetNamePatternIdAttribute(): ?int
    {
        return $this->signatureIndexedPattern?->target_name_pattern_id;
    }

    public function getTargetNameIdAttribute(): ?int
    {
        // Derive TargetName via the TargetNamePattern relation
        $snp = $this->signatureIndexedPattern?->targetNamePattern;
        return $snp?->target_name_id;
    }

    /**
     * Inverse many-to-many to TargetNameMatchedWord via pivot source_name_matched_words_alter_egos
     */
    public function sourceNameMatchedWords(): BelongsToMany
    {
        return $this->belongsToMany(
            TargetNameMatchedWord::class,
            'source_name_matched_words_alter_egos',
            'alter_ego_id',
            'source_name_matched_word_id'
        );
    }
}
