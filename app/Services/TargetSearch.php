<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\Target;
use App\Models\TargetTokenSignature;
use App\Support\Metrics;
use App\Traits\ScalesJobs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class TargetSearch
{
    use ScalesJobs;

    public function __construct(
        private readonly TargetService $targets,
        private readonly WordMatchService $wordMatch,
        private readonly ListPatternsService $patterns,
        private readonly FillPatternSignaturesService $fill,
        private readonly ExpandSignaturedPatternService $expand,
    ) {}

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

        $standardShortEnoughPatterns = $this->patterns->listWithinMinLength((int) $target->signature->length);
        Log::info('patterns.short_enough.collected', [
            'target_id' => $target->id,
            'signature_length' => (int) $target->signature->length,
            'count' => $standardShortEnoughPatterns->count(),
        ]);

        $filteredPatterns = $this->patterns->filterPatternsForTarget(
            (int) $target->signature->length,
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
}
