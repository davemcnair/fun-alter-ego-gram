<?php

namespace App\Jobs;

use App\Models\Pattern;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Services\SignatureFillService;
use App\Models\SourceNamePattern;
use App\Services\WordMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ProcessPatternJob
 * ------------------
 * Purpose:
 *  Processes a single SourceNamePattern for a given SourceName by generating alter-ego phrases
 *  that fit the source name’s letters. This job is designed to run in small time/count slices so
 *  a queue worker can interleave work across many patterns without long-running tasks.
 *
 * Key ideas:
 *  - Sliced processing: generation is bounded by either a soft time budget (slice_ms_budget, ms)
 *    or a count cap (phrases_per_step_cap). If a slice boundary is reached and some phrases were
 *    produced, the job re-dispatches itself to continue from where it left off (idempotent in
 *    practice because we use firstOrCreate on phrases and pattern status tracking).
 *  - Status flow: SourceNamePattern moves pending → processing → done. The parent SourceName updates
 *    current_pattern, patterns_searched, elapsed_seconds, and transitions to completed when no
 *    pending/processing patterns remain.
 *  - Inputs/Outputs: The job reads words matched to the source’s signature via WordMatchService,
 *    expands anagram siblings for those words to widen the candidate pool, and emits/records new
 *    AlterEgo rows mapped to the current pattern.
 *  - Configuration:
 *      search.slice_ms_budget      int ms soft budget for one slice (0 = disabled)
 *      search.phrases_per_step_cap int max phrases to create per slice (0 = unlimited)
 *      search.queue                string|null name of queue (if set) used for (re)dispatch
 *
 * Notes:
 *  - Word candidates are grouped by token_type/list_type from WordMatchService and flattened to
 *    token => [word,...] for generation.
 *  - Logging is best-effort; any logging failures are swallowed to avoid failing the job.
 */
class FillPatternSignaturesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum seconds a worker may allow this job to run before timing out.
     */
    public int $timeout = 300; // seconds

    /**
     * How many times the job may be attempted.
     */
    public int $tries = 3;


    public function __construct(public int $sourceNamePatternId)
    {
    }

    /**
     * Backoff (in seconds) between retries.
     * @return int|array<int,int>
     */
    public function backoff(): int|array
    {
        return 2;
    }

    /**
     * Tags for Horizon/queue monitoring.
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['fill-pattern-signatures', 'source-name-pattern:'.$this->sourceNamePatternId];
    }

    /**
     * Handle filling pattern signatures for the associated SourceNamePattern.
     */
    public function handle(
        WordMatchService $wordMatchService,
        SignatureFillService $signatureFillService
    ): void
    {
        $sourceNamePattern = SourceNamePattern::with('sourceName')
            ->find($this->sourceNamePatternId);
        /** @var SourceName $source */
        $source = $sourceNamePattern->sourceName;

        // If already done, skip
        if ($sourceNamePattern->status === 'done') return;

        // Atomically claim the pattern if it's pending
        $sourceNamePattern->status = 'processing';
        $sourceNamePattern->save();

        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions($sourceNamePattern->pattern_template);

        $tokenWordsByListTYpe = $wordMatchService->findMatches($source->signature);

        $candidateWordSignaturesByToken = $this->flattenTokenWordsToWordSignatures($tokenWordsByListTYpe);

        $signaturePatterns = [];
        $count = 0;
        foreach ($signatureFillService->generateSignaturePatterns(
            $source->signature,
            $patternTokenPositions,
            $candidateWordSignaturesByToken
        ) as $signaturePattern) {
            $signaturePatterns[] = [
                'source_name_pattern_id' => $sourceNamePattern->id,
                'pattern' => $signaturePattern,
            ];
            $count++;
            if ($count % 1000 == 0) {
                try { Log::info($source->name . '/' . ($sourceNamePattern->template ?? '') . ' :' . $count . ' filled'); } catch (Throwable $e) {}
                SignatureIndexedPattern::insert($signaturePatterns);
                $signaturePatterns = [];
            }
        }
        // Flush any remaining batched rows
        if (!empty($signaturePatterns)) {
            SignatureIndexedPattern::insert($signaturePatterns);
        }
        try { Log::info($source->name . '/' . ($sourceNamePattern->template ?? '') . ' :' . $count . ' fills completed'); } catch (Throwable $e) {}
        // Dispatch expansion on the configured queue if any
        $queue = config('search.queue');
        $dispatch = ExpandSignatureIndexedPatternsJob::dispatch($sourceNamePattern->id);
        if (!empty($queue)) {
            $dispatch->onQueue($queue);
        }
    }

    /**
     * Flatten the grouped matches structure into token => list of {word, signature}.
     */
    private function flattenTokenWordsToWordSignatures(array $groups): array
    {
        $out = [];
        foreach ($groups as $token => $byList) {
            $bucket = [];
            foreach ($byList as $items) {
                foreach ($items as $item) {
                    $bucket[$item['word']] = $item['signature']; // dedupe by word, prefer first signature (should be identical per word)
                }
            }
            $out[$token] = $bucket;
        }
        return $out;
    }
}
