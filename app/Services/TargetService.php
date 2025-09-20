<?php

namespace App\Services;

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
     * Fill matched patterns for a target by computing mins, filtering, inserting,
     * and enqueuing fills for pending patterns.
     */
    public function fillMatchedPatternsForTarget(Target $target): void
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

        // Dispatch fills for pending
        $pendingIds = $target->fresh()->patterns()->where('status', 'pending')->pluck('id');
        Log::info('fill_jobs.dispatch', [
            'target_id' => $target->id,
            'pending_count' => $pendingIds->count(),
        ]);
        foreach ($pendingIds as $pid) {
            $this->scaledDispatch(FillPatternSignaturesJob::class, (int)$pid);
        }
        Metrics::counter('fill_jobs_dispatched', (int)$pendingIds->count(), [ 'target_id' => $target->id ]);
        // Step 6: set target to running
        $target->status = 'running';
        $target->save();
        Metrics::end($timer, [ 'target_id' => $target->id ]);
    }

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

        $signatureDto = NameNormalizer::anagramSignature($name);
        $display = NameNormalizer::displayName($name);


        // Validation: normalized key must be non-empty
        if ($normalizedKey === '') {
            Log::warning('TargetCreationService.create invalid name after normalization', [
                'original_input' => mb_substr($originalInput, 0, 80),
            ]);
            abort(422, 'Name is invalid after normalization');
        }

        // Log observability fields
        Log::info('target.create.begin', [
            'original_input' => mb_substr($originalInput, 0, 80),
            'normalized_key' => $normalizedKey,
            'include_boring' => $includeBoring,
        ]);

        $tTimer = Metrics::start('target_create_ms', [ 'normalized_key' => $normalizedKey ]);
        // if word has anagram, its signature will be found, otherwise created
        $signature = Signature::firstOrCreate(['signature' => $signatureDto->signature], $signatureDto->defaults);
        Log::info('signature.ensure', [ 'signature_id' => $signature->id, 'signature' => $signature->signature ]);
        $target = Target::firstOrCreate(
            ['normalized_key' => $normalizedKey],
            [
                'name' => $display,
                'status' => 'idle',
                'signature_id' => $signature->id
            ]
        );
        Metrics::counter('targets_created', 1, [ 'target_id' => $target->id ]);

        // Step 1: Find matches and link to this target
        $this->wordMatchService->linkMatchesToTarget($target, ['include_boring' => $includeBoring]);

        // Steps 2–5: compute mins, filter, insert, and enqueue fills for this target
        $this->fillMatchedPatternsForTarget($target->fresh());
        Metrics::end($tTimer, [ 'target_id' => $target->id ]);
        Log::info('target.create.end', [ 'target_id' => $target->id ]);

        return $target;
    }
}
