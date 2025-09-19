<?php

namespace App\Jobs;

use App\Services\PhraseBuilderService;
use App\Services\ExpandSignatureIndexedPatternService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpandSignatureIndexedPatternsJob implements ShouldQueue
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
        return ['expand-signatureIndexed-patterns', 'target-pattern:' . $this->targetNamePatternId];
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
                    'ExpandSignatureIndexed [TP:%d "%s" for "%s"]',
                    $tp->id,
                    $template,
                    $target
                );
            }
        } catch (\Throwable $e) {
            // ignore and fall back
        }
        return 'ExpandSignatureIndexed [TP:'.$this->targetNamePatternId.']';
    }

    /**
     * Handle filling pattern signatures for the associated TargetNamePattern.
     */
    public function handle(
        PhraseBuilderService $phraseBuilderService
    ): void
    {
        // Delegate to extracted service to perform the expansion logic while
        // keeping the same method signature for backwards compatibility in tests.
        app(ExpandSignatureIndexedPatternService::class)
            ->expandWithBuilder($this->targetNamePatternId, $phraseBuilderService);
    }

}
