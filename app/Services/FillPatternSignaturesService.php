<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Jobs\ExpandSignatureIndexedPatternsJob;
use App\Models\Pattern;
use App\Models\TargetSignatureIndexedPattern;
use App\Models\TargetPattern;
use App\Traits\HelpsMatchWords;
use App\Traits\ScalesJobs;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Throwable;

class FillPatternSignaturesService
{
    use HelpsMatchWords, ScalesJobs;

    public function __construct(public SignatureFillService $signatureFillService) {}
    /**
     * Execute the fill step for a TargetNamePattern using the provided collaborator services.
     */
    public function fillWithServices(
        int $targetPatternId,
    ): void {
        /** @var TargetPattern $targetPattern */
        $targetPattern = TargetPattern::find($targetPatternId);
        if (!$targetPattern) {
            return;
        }

        if ($targetPattern->status !== TargetPatternStatus::pending) return;

        // Atomically claim the pattern if it's pending
        $targetPattern->status = TargetPatternStatus::processing;
        $targetPattern->save();

        // Mark start time when first claimed (works for both inline and queued)
        if ($targetPattern->started_at === null) {
            $targetPattern->started_at = now();
            $targetPattern->save();
        }

        $timer = Metrics::start('fill_duration_ms', [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
        ]);
        Log::info('fill.start', [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
            'template' => (string)($targetPattern->pattern->template ?? ''),
        ]);

        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions((string)$targetPattern->pattern->template);

        // Algorithmic ordering
        // Use the normalized signature string from the related Signature model
        $targetSignature = $targetPattern->target->signature->signature;

        // Rarity-first slot ordering: order by ascending candidate count per token
        $candidateCounts = [];
        foreach ($targetPattern->target->tokenSignatureWords as $targetTokenSignatureWord) {
            $token_id = (int)$targetTokenSignatureWord->tokenSignatureWord->tokenSignature->token_id;
            $candidateCounts[$token_id] = ($candidateCounts[$token_id] ?? 0) + 1;
        }
        $orderedSlots = $patternTokenPositions; // copy
        uasort($orderedSlots, function($aTokenId, $bTokenId) use ($candidateCounts) {
            $ac = $candidateCounts[$aTokenId] ?? PHP_INT_MAX;
            $bc = $candidateCounts[$bTokenId] ?? PHP_INT_MAX;
            if ($ac === $bc) return 0;
            return $ac < $bc ? -1 : 1;
        });
        try { \Log::info('FillPatternSignaturesService: rarity order token_ids=' . implode(',', array_values($orderedSlots))); } catch (\Throwable $e) {}

        $signaturePatterns = [];
        $count = 0;
        foreach ($this->signatureFillService->generateSignaturePatterns(
            $targetSignature,
            $orderedSlots,
            $targetPattern->target->tokenSignatureWords
        ) as $signaturePattern) {
            $signaturePatterns[] = [
                'target_pattern_id' => $targetPattern->id,
                'pattern' => $signaturePattern,
            ];
            $count++;
            if ($count % 1000 === 0) {
                try {
                    Log::info($targetPattern->target->name . '/' . ((string)$targetPattern->pattern->template) . ' :' . $count . ' filled');
                } catch (Throwable $e) {}
                TargetSignatureIndexedPattern::insert($signaturePatterns);
                $signaturePatterns = [];
            }
        }

        // Flush any remaining batched rows
        if (!empty($signaturePatterns)) {
            TargetSignatureIndexedPattern::insert($signaturePatterns);
        }
        Metrics::counter('signature_indexed_patterns_generated', $count, [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
        ]);
        $durationMs = Metrics::end($timer, [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
            'generated' => $count,
        ]);
        Log::info('fill.complete', [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
            'generated' => $count,
            'duration_ms' => $durationMs,
        ]);
        // Dispatch expansion; scale based on queue configuration
        $this->scaledDispatch(ExpandSignatureIndexedPatternsJob::class, $targetPattern->id);
        // Note: finished_at and elapsed_ms are now computed centrally in
        // ExpandSignatureIndexedPatternService after expansion completes so
        // that both queued and inline execution paths record timing.
    }
}
