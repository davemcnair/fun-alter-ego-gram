<?php

namespace App\Jobs;

use App\Services\FillPatternSignaturesService;
use App\Services\SignatureFillService;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use App\Traits\HelpsMatchWords;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

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
        return ['fill-pattern-signatures', 'target-pattern:'.$this->targetNamePatternId];
    }

    /**
     * Customize the console/Horizon display name to include context.
     */
    public function displayName(): string
    {
        try {
            $tp = \App\Models\TargetPattern::with(['target', 'pattern'])->find($this->targetNamePatternId);
            if ($tp) {
                $target = $tp->target?->name ?? 'unknown-target';
                $template = (string)($tp->pattern->template ?? '');
                return sprintf(
                    'FillPatternSignatures [TP:%d "%s" for "%s"]',
                    $tp->id,
                    $template,
                    $target
                );
            }
        } catch (Throwable $e) {
            // ignore and fall back
        }
        return 'FillPatternSignatures [TP:'.$this->targetNamePatternId.']';
    }

    /**
     * Handle filling pattern signatures for the associated TargetNamePattern.
     */
    public function handle(
        SignatureFillService $signatureFillService
    ): void
    {
        Log::info('job.fill.start', [
            'job' => 'FillPatternSignaturesJob',
            'target_pattern_id' => $this->targetNamePatternId,
            'queue' => $this->queue ?? null,
            'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
        ]);
        Metrics::counter('job_fill_started', 1, [
            'target_pattern_id' => $this->targetNamePatternId,
        ]);
        try {
            app(FillPatternSignaturesService::class)
                ->fillWithServices($this->targetNamePatternId, $signatureFillService);
            Metrics::counter('job_fill_succeeded', 1, [
                'target_pattern_id' => $this->targetNamePatternId,
            ]);
            Log::info('job.fill.success', [
                'target_pattern_id' => $this->targetNamePatternId,
            ]);
        } catch (Throwable $e) {
            Metrics::counter('job_fill_failed', 1, [
                'target_pattern_id' => $this->targetNamePatternId,
            ]);
            Log::error('job.fill.error', [
                'target_pattern_id' => $this->targetNamePatternId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

}
