<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Signature;
use App\Models\Target;
use App\Support\NameNormalizer;
use App\Support\Metrics;
use App\Traits\HelpsMatchWords;
use App\Traits\ScalesJobs;
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
    public function create(string $name, bool $includeBoring = false): Target
    {
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
        $signature = Signature::firstOrCreate(['signature' => $signatureDto->signature], $signatureDto->defaults);
        return Target::firstOrCreate(
            ['normalized_key' => $normalizedKey],
            [
                'name' => $display,
                'status' => TargetStatus::filterable,
                'signature_id' => $signature->id
            ]
        );
    }

    public function fillPatterns(Target $target, bool $includeBoring = false): void
    {
        if ($target->status!=TargetStatus::filterable) {
            Log::warning('TargetService.process not in status fillable');
            return;
        }
        // Step 1: Find matches and link to this target
        $this->wordMatchService->linkMatchesToTarget($target, ['include_boring' => $includeBoring]);

        // Steps 2–5: compute mins, filter, insert, and enqueue fills for this target
        $this->filterMatchingPatternsForTarget($target->fresh());
    }

    public function processPendingPatterns(Target $target): void
    {
        $target->status = TargetStatus::processing;
        $target->save();
        $pendingIds = $target
            ->patterns()
            ->where('status', TargetPatternStatus::pending)
            ->pluck('id');
        Log::info('fill_jobs.dispatch', [
            'target_id' => $target->id,
            'pending_count' => $pendingIds->count(),
        ]);
        foreach ($pendingIds as $pid) {
            $this->scaledDispatch(FillPatternSignaturesJob::class, (int)$pid);
        }
    }

    /**
     * Create filtered matched patterns for a target by computing mins
     */
    private function filterMatchingPatternsForTarget(Target $target): void
    {
        $timer = Metrics::start('fill_matched_patterns_ms', [
            'target_id' => $target->id,
        ]);
        // Compute minimum lengths from current matching words
        [$storedMinLengths, $matchedMinLengths] =
            $this->wordMatchService->extractTargetTokenSignatureWordMinimumLengths($target->matchingTokenSignatureWords);

        // List patterns within the target signature length
        $standardShortEnoughPatterns = $this->patternsService->listWithinMinLength((int) $target->signature->length);
        Log::info('patterns.short_enough.collected', [
            'target_id' => $target->id,
            'signature_length' => (int) $target->signature->length,
            'count' => $standardShortEnoughPatterns->count(),
        ]);

        // Filter patterns according to stored and matched mins
        $filteredPatterns = $this->patternsService->filterPatternsForTarget(
            $target->signature,
            $standardShortEnoughPatterns,
            $storedMinLengths,
            $matchedMinLengths
        );

        // Bulk insert TargetPattern rows
        $now = now();
        $filteredCount = $filteredPatterns->count();
        Log::info('target_patterns.inserting', [
            'target_id' => $target->id,
            'count' => $filteredCount,
        ]);
        /** @var Pattern $pattern */
        $bulk = $filteredPatterns->map(function ($pattern) use ($target, $now) {
            return [
                'target_id' => $target->id,
                'pattern_id' => $pattern->id,
                'popularity_rank' => $pattern->popularity_rank,
                'status' => $pattern->pattern_type == 'standard' ? 'pending' : 'deferred',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
        if ($bulk->isNotEmpty()) {
            // Use insertOrIgnore to respect unique (target_id, pattern_id) and keep operation idempotent
            DB::table('target_patterns')->insertOrIgnore($bulk->toArray());
            Metrics::counter('target_patterns_inserted', $filteredCount, [ 'target_id' => $target->id ]);
        }
    }

}
