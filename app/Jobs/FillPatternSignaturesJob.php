<?php

namespace App\Jobs;

use App\Services\FillPatternSignaturesService;
use App\Services\SignatureFillService;
use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
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
 *  Processes a single TargetNamePattern for a given TargetName by generating alter-ego phrases
 *  that fit the source name’s letters. This job is designed to run in small time/count slices so
 *  a queue worker can interleave work across many patterns without long-running tasks.
 *
 * Key ideas:
 *  - Sliced processing: generation is bounded by either a soft time budget (slice_ms_budget, ms)
 *    or a count cap (phrases_per_step_cap). If a slice boundary is reached and some phrases were
 *    produced, the job re-dispatches itself to continue from where it left off (idempotent in
 *    practice because we use firstOrCreate on phrases and pattern status tracking).
 *  - Status flow: TargetNamePattern moves pending → processing → done. The parent TargetName updates
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HelpsMatchWords;

    /**
     * Maximum seconds a worker may allow this job to run before timing out.
     */
    public int $timeout = 300; // seconds

    /**
     * How many times the job may be attempted.
     */
    public int $tries = 3;


    public function __construct(public int $targetNamePatternId)
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
        return ['fill-pattern-signatures', 'source-name-pattern:'.$this->targetNamePatternId];
    }

    /**
     * Handle filling pattern signatures for the associated TargetNamePattern.
     */
    public function handle(
        WordMatchService $wordMatchService,
        SignatureFillService $signatureFillService
    ): void
    {
        // Delegate to extracted service while preserving signature for tests
        app(FillPatternSignaturesService::class)
            ->fillWithServices($this->targetNamePatternId, $wordMatchService, $signatureFillService);
    }

}
