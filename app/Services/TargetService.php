<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetTokenSignature;
use App\Support\NameNormalizer;
use App\Support\Metrics;
use App\Traits\HelpsMatchWords;
use App\Traits\ScalesJobs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TargetService
{
    use HelpsMatchWords, ScalesJobs;

    public function __construct(
        private readonly ListPatternsService $patternsService,
        private readonly WordMatchService    $wordMatchService,
    ) {}

    /**
     * Create a Target from the provided display name, link candidate patterns,
     * enqueue pending fills, and return the created Target.
     *
     * @param string $name
     * @param bool $includeBoring
     * @return Target
     */
    public function create(string $name): Target
    {
        // todo: store , bool $includeBoring = false
        $originalInput = $name;
        $name = trim($name);
        $normalizedKey = NameNormalizer::canonicalKey($name);

        // Validation: normalized key must be non-empty
        if ($normalizedKey === '') {
            Log::warning('TargetCreationService.create invalid name after normalization', [
                'original_input' => mb_substr($originalInput, 0, 80),
            ]);
            abort(422, 'Name is invalid after normalization');
        }
        $display = NameNormalizer::displayName($name);

        $signatureDto = NameNormalizer::anagramSignature($name);
        // if word has anagram, its signature will be found, otherwise created
        $signature = Signature::where('signature', $signatureDto->signature)->first();
        if (!$signature) {
            $signature = new Signature();
            $signature->signature = $signatureDto->signature;
            foreach($signatureDto->defaults as $attr => $value){
                $signature->$attr = $value;
            }
            $signature->save();
        }
        $found = Target::where('normalized_key', $normalizedKey)->first();
        if ($found) { return $found; }
        $target = new Target();
        $target->normalized_key = $normalizedKey;
        $target->name = $display;
        $target->status = TargetStatus::filterable;
        $target->signature_id = $signature->id;
        $target->save();
        return $target;
    }

    public function processTarget(Target $target, Collection $matchingSignatures): void
    {
        if (!$target->isProcessable()) {
            Log::warning('TargetCreationService.processTarget not processable', []);
            return;
        }
        if ($matchingSignatures->isEmpty()) {
            $target->status = TargetStatus::processed;
            $target->save();
            return;
        }

        // Increase execution time limit for large targets with many patterns
        // This prevents timeout when processing synchronously
        $originalTimeLimit = ini_get('max_execution_time');
        set_time_limit(300); // 5 minutes for large targets

        try {
            $target->status = TargetStatus::filterable;
            $target->save();
            $this->filterPatterns($target, $matchingSignatures);
            $target->status = TargetStatus::processing;
            $target->save();
            $this->processDeferredPatterns($target);
        } finally {
            // Restore original time limit
            if ($originalTimeLimit !== false) {
                set_time_limit((int)$originalTimeLimit);
            }
        }
    }

    private function filterPatterns(Target $target, Collection $matchingSignatures): void
    {
        if ($target->status !== TargetStatus::filterable) {
            Log::warning('TargetService.process not in status filterable');
            return;
        }
        TargetTokenSignature::bulkInsertOrIgnore($target, $matchingSignatures);
        
        // Free memory from the large matching signatures collection
        // The target now has the relationships loaded, so we don't need the original collection
        unset($matchingSignatures);

        // Steps 2–5: compute mins, filter, insert, and enqueue fills for this target
        $this->filterMatchingPatternsForTarget($target);
    }

    private function processDeferredPatterns(Target $target): void
    {
        //todo status check
        $pendingIds = $target
            ->patterns()
            ->where('status', TargetPatternStatus::PENDING)
            ->pluck('id');

        $pendingCount = $pendingIds->count();
        Log::info('fill_patterns', [
            'target' => $target->name,
            'pending_patterns' => $pendingCount,
        ]);
        
        if (config('search.queue')){
            Log::info('Async search.');
        }
        
        foreach ($pendingIds as $pid) {
            $this->scaledDispatch(FillPatternSignaturesJob::class, (int)$pid);
        }
    }

    /**
     * Create filtered matched patterns for a target by computing mins
     */
    private function filterMatchingPatternsForTarget(Target $target): void
    {
        // Load signature first (small, needed for length check)
        $target->loadMissing('signature');

        // Use query-based approach to compute minimum lengths without loading all models into memory
        // This avoids memory exhaustion with large numbers of token signatures (e.g., 701+)
        [$storedMinLengths, $matchedMinLengths] =
            $this->wordMatchService->extractTargetTokenSignatureMinimumLengthsFromQuery($target);

        // List patterns within the target signature length
        $standardShortEnoughPatterns = $this->patternsService->listWithinMinLength((int) $target->signature->length);
        Log::info('patterns.short_enough.collected', [
            'target_id' => $target->id,
            'signature_length' => (int) $target->signature->length,
            'count' => $standardShortEnoughPatterns->count(),
        ]);

        // Filter patterns according to stored and matched mins
        $filteredPatterns = $this->patternsService->filterPatternsForTarget(
            strlen($target->signature),
            $standardShortEnoughPatterns,
            $storedMinLengths,
            $matchedMinLengths
        );

        // Bulk insert TargetPattern rows
        $filteredCount = $filteredPatterns->count();
        Log::info('target_patterns.inserting', [
            'target' => $target->name,
            'count' => $filteredCount,
        ]);

        /** @var Pattern $pattern */
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
        // Use insertOrIgnore to respect unique (target_id, pattern_id) and keep operation idempotent
        $now = now();
        $rows = $bulk->map(fn($data) => $data + ['created_at' => $now, 'updated_at' => $now]);
        DB::table('target_patterns')->insertOrIgnore($rows->toArray());
        Metrics::counter('target_patterns_inserted', $filteredCount, [ 'target_id' => $target->id ]);
    }

}
