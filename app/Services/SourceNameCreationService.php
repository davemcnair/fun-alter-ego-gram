<?php

namespace App\Services;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\Log;

class SourceNameCreationService
{
    use HelpsMatchWords;

    public function __construct(
        private ListPatternsService $patternsService,
        private WordMatchService $wordMatchService,
    ) {}

    /**
     * Create a SourceName from the provided display name, link candidate patterns,
     * enqueue pending fills, and return the created SourceName.
     *
     * @return array{source: SourceName, filtered_count:int, pending_count:int}
     */
    public function create(string $name, bool $includeBoring = false): array
    {
        $name = trim($name);
        $signature = $this->makeSignature($name);

        $source = SourceName::create([
            'name' => $name,
            'signature' => $signature,
            'status' => 'idle',
        ]);

        // Step 1: store matched words and get involved token ids
        $tokenSignatureWords = $this->wordMatchService->storeNewSourceNameMatchedTokenSignatureWords($source, $includeBoring);

        // Step 2: compute min lengths (id-keyed arrays)
        [$storedMinLengths, $matchedMinLengths] =
            $this->wordMatchService->extractMatchingTokenWordMinimumLengths($source->signature, $tokenSignatureWords);

        // Step 3: list short-enough patterns and filter
        $standardShortEnoughPatterns = $this->patternsService->listWithinMinLength(strlen($signature));
        $filteredPatterns = $this->patternsService->filterPatternsForSource(
            $signature,
            $standardShortEnoughPatterns,
            $storedMinLengths,
            $matchedMinLengths
        );

        // Step 4: bulk insert SourceNamePattern rows
        $now = now();
        Log::info('inserting filtered patterns, n= '. $filteredPatterns->count());
        /** @var Pattern $pattern */
        $bulk = $filteredPatterns->map(function($pattern) use ($source, $now) {
            return [
                'source_name_id' => $source->id,
                'pattern_id' => $pattern->id,
                'popularity_rank' => $pattern->popularity_rank,
                'status' => $pattern->pattern_type == 'standard' ? 'pending' : 'deferred',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
        if ($bulk->isNotEmpty()) {
            SourceNamePattern::insert($bulk->toArray());
        }

        // Step 5: dispatch fills for pending
        $pendingIds = $source->fresh()->patterns()->where('status','pending')->pluck('id');
        Log::info('pending ids', $pendingIds->toArray());
        $queue = config('search.queue');
        foreach ($pendingIds as $pid) {
            $dispatch = FillPatternSignaturesJob::dispatch((int)$pid);
            if (!empty($queue)) { $dispatch->onQueue($queue); }
        }

        // Step 6: set source to running
        $source->status = 'running';
        $source->save();

        return [
            'source' => $source,
            'filtered_count' => $filteredPatterns->count(),
            'pending_count' => count($pendingIds),
        ];
    }
}
