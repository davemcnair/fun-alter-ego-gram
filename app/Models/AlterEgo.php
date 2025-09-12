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
        return $this->belongsTo(SignatureIndexedPattern::class);
    }

    // Computed attributes to expose denormalized relations without DB columns
    public function getSourceNamePatternIdAttribute(): ?int
    {
        return $this->signatureIndexedPattern?->source_name_pattern_id;
    }

    public function getSourceNameIdAttribute(): ?int
    {
        // Derive SourceName via the SourceNamePattern relation
        $snp = $this->signatureIndexedPattern?->sourceNamePattern;
        return $snp?->source_name_id;
    }

    /**
     * Inverse many-to-many to SourceNameMatchedWord via pivot source_name_matched_words_alter_egos
     */
    public function sourceNameMatchedWords(): BelongsToMany
    {
        return $this->belongsToMany(
            SourceNameMatchedWord::class,
            'source_name_matched_words_alter_egos',
            'alter_ego_id',
            'source_name_matched_word_id'
        );
    }
}
