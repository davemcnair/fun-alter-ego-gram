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
        } catch (\Throwable $e) {
            // ignore and fall back
        }
        return 'FillPatternSignatures [TP:'.$this->targetNamePatternId.']';
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
