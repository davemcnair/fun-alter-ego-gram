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

        if ($targetPattern->status !== TargetPatternStatus::PENDING) return;

        // Atomically claim the pattern if it's pending
        $targetPattern->status = TargetPatternStatus::PROCESSING;
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

        // Ensure required relations are loaded to avoid N+1 when building candidates
        $targetPattern->loadMissing(
            'target.signature',
            'target.tokenSignatures.tokenSignature.signature'
        );

        // Algorithmic ordering
        // Use the normalized signature string from the related Signature model
        $targetLetterCountsNeeded = $targetPattern->target->signature->letterCounts();

        // Rarity-first slot ordering: order by ascending candidate count per token
        $candidateCounts = [];
        foreach ($targetPattern->target->tokenSignatures as $targetTokenSignature) {
            $token_id = (int)$targetTokenSignature->tokenSignature->token_id;
            $candidateCounts[$token_id] = ($candidateCounts[$token_id] ?? 0) + 1;
        }
        $orderedSlots = $patternTokenPositions; // copy
        uasort($orderedSlots, function($aTokenId, $bTokenId) use ($candidateCounts) {
            $ac = $candidateCounts[$aTokenId] ?? PHP_INT_MAX;
            $bc = $candidateCounts[$bTokenId] ?? PHP_INT_MAX;
            if ($ac === $bc) return 0;
            return $ac < $bc ? -1 : 1;
        });

        $signaturePatterns = [];
        $count = 0;
        foreach ($this->signatureFillService->generateSignaturePatterns(
            $targetLetterCountsNeeded,
            $orderedSlots,
            $targetPattern->target->tokenSignatures
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
