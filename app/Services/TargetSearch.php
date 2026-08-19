<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Target;
use App\Models\TargetTokenSignature;
use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Support\Metrics;
use App\Traits\ScalesJobs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class TargetSearch
{
    use ScalesJobs;

    private readonly TargetService $targets;

    private readonly WordMatchService $wordMatch;

    private readonly FillPatternSignaturesService $fill;

    private readonly ExpandSignaturedPatternService $expand;

    public function __construct()
    {
        $this->targets = new TargetService();
        $this->wordMatch = new WordMatchService();
        $this->fill = new FillPatternSignaturesService();
        $this->expand = new ExpandSignaturedPatternService();
    }

    /**
     * Create or reuse a Target and run Target search through fill and expand.
     */
    public function search(string $name): Target
    {
        $started = microtime(true);
        $target = $this->targets->create($name);
        $this->run($target);
        Log::info('Target.search', [
            'name' => $target->name,
            'total_ms' => round((microtime(true) - $started) * 1000, 1),
        ]);

        return $target->fresh();
    }

    /**
     * Re-run Target search for an existing Target (e.g. after a new Token word).
     */
    public function resume(Target $target): Target
    {
        $this->run($target);
        return $target->fresh();
    }

    /**
     * Fill one TargetPattern (queue adapter and explicit pattern search).
     */
    public function fillPattern(int $targetPatternId): void
    {
        $this->fill->fillWithSignatures($targetPatternId);
    }

    /**
     * Expand one TargetPattern's signatured patterns into AlterEgos (queue adapter).
     */
    public function expandPattern(int $targetPatternId): void
    {
        $this->expand->expandSignaturedPatterns($targetPatternId);
    }

    /**
     * Attach this Token Signature and word to Targets whose Signature contains it.
     * Does not resume Target search.
     */
    public function attachWord(int $tokenSignatureWordId): void
    {
        $word = TokenSignatureWord::find($tokenSignatureWordId);
        if (! $word || $word->is_deferred || ! in_array((string) $word->list_type, ['fun', 'ok'], true)) {
            return;
        }

        $word->loadMissing('tokenSignature.signature');
        $tokenSignatureId = (int) $word->token_signature_id;
        $signature = $word->tokenSignature->signature;
        $count = 0;

        $this->wordMatch
            ->findTargetsContainingSignature($signature)
            ->orderBy('id')
            ->chunkById(1000, function ($targets) use ($tokenSignatureId, $tokenSignatureWordId, &$count) {
                $now = now();
                $signatureRows = [];
                $wordRows = [];
                foreach ($targets as $target) {
                    $targetId = (int) $target->id;
                    $signatureRows[] = [
                        'target_id' => $targetId,
                        'token_signature_id' => $tokenSignatureId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $wordRows[] = [
                        'target_id' => $targetId,
                        'token_signature_word_id' => $tokenSignatureWordId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($signatureRows !== []) {
                    DB::table('target_token_signatures')->insertOrIgnore($signatureRows);
                    DB::table('target_token_signature_words')->insertOrIgnore($wordRows);
                    $count += count($signatureRows);
                }
            });

        Log::info('Target.attachWord', [
            'token_signature_word_id' => $tokenSignatureWordId,
            'matches' => $count,
        ]);
    }

    private function run(Target $target): void
    {
        $matchingSignatures = $this->wordMatch->findMatchingTokenSignatures($target->signature);

        if ($matchingSignatures->isEmpty()) {
            if ($target->tokenSignatures()->doesntExist()) {
                $target->status = TargetStatus::processed;
                $target->save();
            }
            return;
        }

        $originalTimeLimit = ini_get('max_execution_time');
        set_time_limit(300);

        try {
            $target->status = TargetStatus::filterable;
            $target->save();
            $this->attachMatchingSignaturesAndPatterns($target, $matchingSignatures);
            $target->status = TargetStatus::processing;
            $target->save();
            $this->dispatchPendingFills($target);
        } finally {
            if ($originalTimeLimit !== false) {
                set_time_limit((int) $originalTimeLimit);
            }
        }
    }

    private function attachMatchingSignaturesAndPatterns(Target $target, Collection $matchingSignatures): void
    {
        TargetTokenSignature::bulkInsertOrIgnore($target, $matchingSignatures);
        unset($matchingSignatures);

        $target->loadMissing('signature');

        [$storedMinLengths, $matchedMinLengths] =
            $this->wordMatch->extractTargetTokenSignatureMinimumLengthsFromQuery($target);

        $targetLength = (int) $target->signature->length;
        $standardShortEnoughPatterns = $this->patternsWithinMinLength($targetLength);
        Log::info('patterns.short_enough.collected', [
            'target_id' => $target->id,
            'signature_length' => $targetLength,
            'count' => $standardShortEnoughPatterns->count(),
        ]);

        $filteredPatterns = $this->filterPatternsForTarget(
            $targetLength,
            $standardShortEnoughPatterns,
            $storedMinLengths,
            $matchedMinLengths
        );

        $filteredCount = $filteredPatterns->count();
        Log::info('target_patterns.inserting', [
            'target' => $target->name,
            'count' => $filteredCount,
        ]);

        $bulk = $filteredPatterns->map(function ($pattern) use ($target) {
            return [
                'target_id' => $target->id,
                'pattern_id' => $pattern->id,
                'popularity_rank' => $pattern->popularity_rank,
                'status' => $pattern->pattern_type === 'standard'
                    ? TargetPatternStatus::PENDING
                    : TargetPatternStatus::DEFERRED,
            ];
        });
        if ($bulk->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $bulk->map(fn ($data) => $data + ['created_at' => $now, 'updated_at' => $now]);
        DB::table('target_patterns')->insertOrIgnore($rows->toArray());
        Metrics::counter('target_patterns_inserted', $filteredCount, ['target_id' => $target->id]);
    }

    private function dispatchPendingFills(Target $target): void
    {
        $pendingIds = $target
            ->patterns()
            ->where('status', TargetPatternStatus::PENDING)
            ->pluck('id');

        Log::info('fill_patterns', [
            'target' => $target->name,
            'pending_patterns' => $pendingIds->count(),
        ]);

        foreach ($pendingIds as $pid) {
            $this->scaledDispatch(FillPatternSignaturesJob::class, (int) $pid);
        }
    }

    private function patternsWithinMinLength(int $totalLength): Collection
    {
        return Pattern::where('min_total_length', '<=', $totalLength)
            ->orderBy('popularity_rank')
            ->get();
    }

    /**
     * @param array<int, int> $storedWordBasedMins
     * @param array<int, int> $matchingWordBasedMins
     */
    private function filterPatternsForTarget(
        int $targetLength,
        Collection $patterns,
        array $storedWordBasedMins,
        array $matchingWordBasedMins
    ): Collection {
        $tokenIdsByName = Token::all()->pluck('id', 'name')->toArray();

        return $patterns->filter(function ($row) use (
            $storedWordBasedMins,
            $matchingWordBasedMins,
            $targetLength,
            $tokenIdsByName
        ) {
            $dynamicMin = 0;
            foreach ($tokenIdsByName as $name => $id) {
                if ($row->has($name)) {
                    if (! isset($matchingWordBasedMins[$id])) {
                        return false;
                    }
                    $count = match ($name) {
                        Token::TOKEN_NAME_FORENAME => (int) $row->forename_count,
                        Token::TOKEN_NAME_SURNAME => (int) $row->surname_count,
                        default => 1,
                    };
                    $count = max(1, $count);
                    $stored = (int) ($storedWordBasedMins[$id] ?? 0);
                    $matched = (int) $matchingWordBasedMins[$id];
                    $effectiveMin = max($stored, $matched);
                    $dynamicMin += $effectiveMin * $count;
                    if ($dynamicMin > $targetLength) {
                        return false;
                    }
                }
            }

            return $dynamicMin <= $targetLength;
        });
    }
}
