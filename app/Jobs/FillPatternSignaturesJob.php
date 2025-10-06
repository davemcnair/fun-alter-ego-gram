<?php

namespace App\Jobs;

use App\Models\TargetPattern;
use App\Services\FillPatternSignaturesService;
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


    public function __construct(public int $targetPatternId)
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
        return ['fill-pattern-signatures', 'target-pattern:'.$this->targetPatternId];
    }

    /**
     * Customize the console/Horizon display name to include context.
     */
    public function displayName(): string
    {
        try {
            $tp = TargetPattern::find($this->targetPatternId);
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
        return 'FillPatternSignatures [TP:'.$this->targetPatternId.']';
    }

    /**
     * Handle filling pattern signatures for the associated TargetNamePattern.
     */
    public function handle(
        FillPatternSignaturesService $fillPatternSignaturesService
    ): void
    {
        try {
            $fillPatternSignaturesService->fillWithServices($this->targetPatternId);
        } catch (Throwable $e) {
            Log::error('job.fill.error', [
                'target_pattern_id' => $this->targetPatternId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

}
