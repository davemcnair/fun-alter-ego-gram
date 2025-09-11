<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SourceNameMatchedWord extends Model
{
    // Table name is non-standard pluralization; specify explicitly for clarity
    protected $table = 'source_name_matched_words';

    protected $fillable = [
        'source_name_id',
        'token_signature_word_id',
    ];

    public function sourceName(): BelongsTo
    {
        return $this->belongsTo(SourceName::class);
    }

    public function tokenSignatureWord(): BelongsTo
    {
        return $this->belongsTo(TokenSignatureWord::class);
    }

    /**
     * Many-to-Many: Matched Word <-> Alter Ego via pivot source_name_matched_words_alter_egos
     */
    public function alterEgos(): BelongsToMany
    {
        return $this->belongsToMany(
            AlterEgo::class,
            'source_name_matched_words_alter_egos',
            'source_name_matched_word_id',
            'alter_ego_id'
        );
    }
}
