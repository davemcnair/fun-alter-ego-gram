<?php

namespace App\Models;

use App\Dtos\WordDto;
use App\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        // Use query builder to avoid loading all models into memory
        // This is memory-efficient for targets with many token signature words
        $words = DB::table('target_token_signature_words as ttsw')
            ->join('token_signature_words as tsw', 'tsw.id', '=', 'ttsw.token_signature_word_id')
            ->join('token_signatures as ts', 'ts.id', '=', 'tsw.token_signature_id')
            ->join('tokens as t', 't.id', '=', 'ts.token_id')
            ->where('ttsw.target_id', $this->id)
            ->select([
                'tsw.id as word_id',
                'tsw.word',
                'tsw.list_type',
                'tsw.is_deferred',
                DB::raw("CASE WHEN tsw.list_type = 'ok' AND t.name IN ('forename', 'surname') THEN 1 ELSE 0 END as is_promotable"),
                't.name as token_name',
                'ttsw.usedInPhrase'
            ])
            ->get();

        // Get usage counts efficiently
        $usageCounts = $this->calculateWordUsageCounts();

        $out = [];

        foreach ($words as $row) {
            $token = $row->token_name;
            $list = $row->list_type;

            if (!isset($out[$token][$list])) {
                $out[$token][$list] = [];
            }

            $out[$token][$list][] = new WordDto(
                tokenType: $token,
                word: $row->word,
                listType: $list,
                isPromotable: (bool)($row->is_promotable ?? false),
                id: (string)$row->word_id,
                deferred: (bool)$row->is_deferred,
                used: (bool)$row->usedInPhrase,
                usageCount: (int)($usageCounts[$row->word_id] ?? 0),
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
        // Use SQL aggregation to count word usage without loading all models
        // This is memory-efficient for targets with many alter egos
        // Join through the pivot table to get word usage counts
        $results = DB::table('alter_egos as ae')
            ->join('target_signatured_patterns as tsp', 'tsp.id', '=', 'ae.target_signatured_pattern_id')
            ->join('target_patterns as tp', 'tp.id', '=', 'tsp.target_pattern_id')
            ->join('target_signatured_pattern_target_token_signature as tsptts', 'tsptts.target_signatured_pattern_id', '=', 'tsp.id')
            ->join('target_token_signatures as tts', 'tts.id', '=', 'tsptts.target_token_signature_id')
            ->join('token_signature_words as tsw', 'tsw.token_signature_id', '=', 'tts.token_signature_id')
            ->where('tp.target_id', $this->id)
            ->select([
                'tsw.id as word_id',
                DB::raw('COUNT(DISTINCT ae.id) as usage_count')
            ])
            ->groupBy('tsw.id')
            ->get();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row->word_id] = (int)$row->usage_count;
        }

        return $counts;
    }
}
