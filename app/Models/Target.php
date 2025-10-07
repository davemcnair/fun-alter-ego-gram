<?php

namespace App\Models;

use App\Dtos\WordDto;
use App\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Target extends Model
{
    use HasRelationships;

    protected $with = ['signature'];

    protected $fillable = [
        'name',
        'normalized_key',
        'status',
        'signature_id',
        'last_processed_matches_at',
        'filled_matches_count',
        'new_matches_count'
    ];

    protected $casts = [
        'matches_seen_at' => 'datetime',
        'last_processed_matches_at' => 'datetime',
        'status' => TargetStatus::class,
    ];

    public function patterns(): HasMany
    {
        return $this->hasMany(TargetPattern::class)->with(['pattern']);
    }

    public function signaturedPatterns(): HasManyThrough
    {
        return $this->hasManyThrough(TargetSignaturedPattern::class, TargetPattern::class);
    }

    public function alterEgos(): HasManyDeep
    {
        return $this->hasManyDeep(AlterEgo::class, [TargetPattern::class, TargetSignaturedPattern::class]);
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }

    public function tokenSignatures(): HasMany
    {
        return $this->hasMany(TargetTokenSignature::class);
    }

    public function tokenSignatureWords(): HasMany
    {
        return $this->hasMany(TargetTokenSignatureWord::class);
    }

    public function isProcessable(): bool
    {
        return in_array($this->status, [TargetStatus::filterable, TargetStatus::processed]);
    }

    /**
     * Lightweight anagram siblings: other targets with the same signature
     * Returns collection of [id, name]
     */
    public function anagramSiblings(): Collection
    {
        if (empty($this->signature)) return collect();
        return static::query()
            ->where('signature', $this->signature)
            ->where('id', '!=', $this->id)
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * Build grouped token word matches for the Target Results page.
     */
    // In App\Models\Target.php - update matchingWordsByUseTokenAndType method

    public function matchingWordsByUseTokenAndType(): array
    {
        $targetTokenSignatureWords = $this->tokenSignatureWords()
            ->with(['tokenSignatureWord.tokenSignature.token'])
            ->get();

        // Get usage counts from DTO (we'll pass this in from controller)
        // For now, calculate here
        $usageCounts = $this->calculateWordUsageCounts();

        $out = [];

        foreach ($targetTokenSignatureWords as $targetTokenSignatureWord) {
            $word = $targetTokenSignatureWord->tokenSignatureWord;
            $token = $word->tokenSignature->token->name;
            $list = $word->list_type;

            if (!isset($out[$token][$list])) {
                $out[$token][$list] = [];
            }

            $out[$token][$list][] = new WordDto(
                tokenType: $token,
                word: $word->word,
                listType: $word->list_type,
                isPromotable: (bool)($word->is_promotable ?? false),
                id: (string)$word->id,
                deferred: (bool)$word->is_deferred,
                used: (bool)$targetTokenSignatureWord->usedInPhrase,
                usageCount: (int)($usageCounts[$word->id] ?? 0),
            );
        }

        // Sort
        foreach ($out as &$lists) {
            foreach ($lists as &$items) {
                usort($items, fn($a, $b) => strcasecmp($a->word, $b->word));
            }
        }

        return $out;
    }

    private function calculateWordUsageCounts(): array
    {
        $alterEgos = $this->alterEgos()
            ->with(['targetSignaturedPattern.targetTokenSignatures.tokenSignature.words'])
            ->get();

        $counts = [];

        foreach ($alterEgos as $alterEgo) {
            foreach ($alterEgo->targetSignaturedPattern->targetTokenSignatures as $tts) {
                foreach ($tts->tokenSignature->words as $word) {
                    if (!isset($counts[$word->id])) {
                        $counts[$word->id] = 0;
                    }
                    $counts[$word->id]++;
                }
            }
        }

        return $counts;
    }
}
