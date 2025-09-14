<?php

namespace App\Services;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Support\NameNormalizer;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TargetCreationService
{
    use HelpsMatchWords;

    public function __construct(
        private readonly ListPatternsService $patternsService,
        private readonly WordMatchService    $wordMatchService,
    ) {}

    /**
     * Create a Target from the provided display name, link candidate patterns,
     * enqueue pending fills, and return the created Target.
     *
     * @return array{source: Target, filtered_count:int, pending_count:int}
     */
    public function create(string $name, bool $includeBoring = false): array
    {
        $originalInput = $name;
        $name = trim($name);
        $normalizedKey = NameNormalizer::canonicalKey($name);
        $signature = NameNormalizer::anagramSignature($name);
        $display = NameNormalizer::displayName($name);

        // Validation: normalized key must be non-empty
        if ($normalizedKey === '') {
            \Log::warning('TargetCreationService.create invalid name after normalization', [
                'original_input' => mb_substr($originalInput, 0, 80),
            ]);
            abort(422, 'Name is invalid after normalization');
        }

        // Log observability fields
        try {
            \Log::debug('TargetCreationService.create', [
                'original_input' => mb_substr($originalInput, 0, 80),
                'normalized_key' => $normalizedKey,
                'signature' => $signature,
                'sig_len' => strlen($signature),
            ]);
        } catch (\Throwable $e) {}

        $target = Target::firstOrCreate(
            ['normalized_key' => $normalizedKey],
            ['name' => $display, 'status' => 'idle', 'signature' => $signature]
        );

        // Step 1: store matched words and get involved token ids
        $tokenSignatureWords = $this->wordMatchService->storeNewTargetMatchedTokenSignatureWords($target, $includeBoring);

        // Step 2: compute min lengths (id-keyed arrays)
        [$storedMinLengths, $matchedMinLengths] =
            $this->wordMatchService->extractMatchingTokenWordMinimumLengths($signature, $tokenSignatureWords);

        // Step 3: list patterns but link ALL standard patterns to this target (pending)
        // We still compute filtered sets for potential future use, but insertion is based on all standard patterns.
        $standardShortEnoughPatterns = $this->patternsService->listWithinMinLength(strlen($signature));
        $filteredPatterns = $this->patternsService->filterPatternsForTarget(
            $signature,
            $standardShortEnoughPatterns,
            $storedMinLengths,
            $matchedMinLengths
        );

        // Step 4: bulk insert TargetPattern rows
        $now = now();
        Log::info('inserting filtered patterns, n= '. $filteredPatterns->count());
        /** @var Pattern $pattern */
        $bulk = $filteredPatterns->map(function($pattern) use ($target, $now) {
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
        }

        // Step 5: dispatch fills for pending
        $pendingIds = $target->fresh()->patterns()->where('status','pending')->pluck('id');
        Log::info('pending ids', $pendingIds->toArray());
        $queue = config('search.queue');
        foreach ($pendingIds as $pid) {
            if (empty($queue)) {
                // Run fills inline to ensure progress without a queue worker
                FillPatternSignaturesJob::dispatchSync((int)$pid);
            } else {
                $dispatch = FillPatternSignaturesJob::dispatch((int)$pid);
                $dispatch->onQueue($queue);
            }
        }

        // Step 6: set target to running
        $target->status = 'running';
        $target->save();

        return [
            'target' => $target,
            'filtered_count' => $filteredPatterns->count(),
            'pending_count' => count($pendingIds),
        ];
    }
}
