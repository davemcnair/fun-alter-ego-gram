<?php

namespace App\Jobs;

use App\Models\Target;
use App\Services\TargetService;
use App\Services\WordMatchService;
use App\Support\Metrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateTargetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $targetId,
        public bool $includeBoring = false,
    ) {}

    public int $tries = 3;
    public int $backoff = 5;

    public function handle(TargetService $targetService, WordMatchService $wordMatchService): void
    {
        $target = Target::query()->find($this->targetId);
        if (!$target) {
            Log::warning('CreateTargetJob.missing_target', [ 'target_id' => $this->targetId ]);
            return; // nothing to do, idempotent
        }

        // Ensure signature exists (in case of manual insert)
        if (empty($target->signature_id)) {
            $sigDto = NameNormalizer::anagramSignature($target->name);
            $signature = Signature::firstOrCreate(['signature' => $sigDto->signature], $sigDto->defaults);
            $target->signature_id = $signature->id;
            $target->save();
        }

        // If already processed, exit quickly
        if ($target->status === 'processed') {
            return; // idempotent
        }

        $timer = Metrics::start('create_target_job_ms', [ 'target_id' => $target->id ]);
        // Mark as processing and set start time if not already set
        $target->status = 'processing';
        $target->save();

        try {
            // Step 1: Link matches
            $wordMatchService->linkMatchesToTarget($target->fresh(), ['include_boring' => $this->includeBoring]);
            // Steps 2–5: compute mins, filter, insert, enqueue fills
            $targetService->fillMatchedPatternsForTarget($target->fresh());
            // Do not mark completed here. Downstream jobs will advance Target status
            // from processing -> running -> completed when appropriate.
        } catch (Throwable $e) {
            Log::error('CreateTargetJob.failed', [ 'target_id' => $target->id, 'error' => $e->getMessage() ]);
            $target->status = 'error';
            $target->save();
            throw $e; // allow retry according to $tries/$backoff
        } finally {
            Metrics::end($timer, [ 'target_id' => $target->id ]);
        }
    }
}
