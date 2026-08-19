<?php

namespace App\Jobs;

use App\Models\TargetPattern;
use App\Services\TargetSearch;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExpandSignaturedPatternsJob implements ShouldQueue
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
        return ['expand-signatured-patterns', 'target-pattern:' . $this->targetNamePatternId];
    }

    /**
     * Customize the console/Horizon display name to include context.
     */
    public function displayName(): string
    {
        $tp = TargetPattern::find($this->targetNamePatternId);
        if ($tp) {
            $target = $tp->target?->name ?? 'unknown-target';
            $template = (string)($tp->pattern->template ?? '');
            return sprintf(
                'ExpandSignatured [TP:%d "%s" for "%s"]',
                $tp->id,
                $template,
                $target
            );
        }
        return 'ExpandSignatured [TP:'.$this->targetNamePatternId.']';
    }

    /**
     * Handle filling pattern signatures for the associated TargetNamePattern.
     */
    public function handle(TargetSearch $targetSearch): void
    {
        Log::info('job.expand.start', [
            'job' => 'ExpandSignaturedPatternsJob',
            'target_pattern_id' => $this->targetNamePatternId,
            'queue' => $this->queue ?? null,
            'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
        ]);
        Metrics::counter('job_expand_started', 1, [
            'target_pattern_id' => $this->targetNamePatternId,
        ]);
        try {
            $targetSearch->expandPattern($this->targetNamePatternId);
            Metrics::counter('job_expand_succeeded', 1, [
                'target_pattern_id' => $this->targetNamePatternId,
            ]);
            Log::info('job.expand.success', [
                'target_pattern_id' => $this->targetNamePatternId,
            ]);
        } catch (Throwable $e) {
            Metrics::counter('job_expand_failed', 1, [
                'target_pattern_id' => $this->targetNamePatternId,
            ]);
            Log::error('job.expand.error', [
                'target_pattern_id' => $this->targetNamePatternId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

}
