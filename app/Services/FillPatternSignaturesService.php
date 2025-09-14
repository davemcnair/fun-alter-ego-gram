<?php

namespace App\Services;

use App\Jobs\ExpandSignatureIndexedPatternsJob;
use App\Models\Pattern;
use App\Models\TargetSignatureIndexedPattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\Log;
use Throwable;

class FillPatternSignaturesService
{
    use HelpsMatchWords;

    /**
     * Execute the fill step for a TargetNamePattern using the provided collaborator services.
     */
    public function fillWithServices(
        int $targetPatternId,
        WordMatchService $wordMatchService,
        SignatureFillService $signatureFillService
    ): void {
        $targetPattern = TargetPattern::with('target')
            ->find($targetPatternId);
        if (!$targetPattern) {
            return; // Nothing to do
        }
        /** @var Target $target */
        $target = $targetPattern->target;

        // If already done, skip
        if ($targetPattern->status === 'done') return;

        // Atomically claim the pattern if it's pending
        $targetPattern->status = 'processing';
        $targetPattern->save();

        $fillStart = microtime(true);
        try { Log::info('FillPatternSignaturesService: start target=' . ($target->name ?? '') . ' template=' . ((string)$targetPattern->pattern->template)); } catch (\Throwable $e) {}

        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions((string)$targetPattern->pattern->template);

        // Algorithmic ordering
        $targetSig = (string)$target->signature;

        // Rarity-first slot ordering: order by ascending candidate count per token
        $candidateCounts = [];
        foreach ($target->matchingTokenSignatureWords as $tokenSignatureWord) {
            $tid = (int)$tokenSignatureWord->tokenSignature->token_id;
            $candidateCounts[$tid] = ($candidateCounts[$tid] ?? 0) + 1;
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
        foreach ($signatureFillService->generateSignaturePatterns(
            $targetSig,
            $orderedSlots,
            $target->matchingTokenSignatureWords
        ) as $signaturePattern) {
            $signaturePatterns[] = [
                'target_pattern_id' => $targetPattern->id,
                'pattern' => $signaturePattern,
            ];
            $count++;
            if ($count % 1000 === 0) {
                try { Log::info($target->name . '/' . ((string)$targetPattern->pattern->template) . ' :' . $count . ' filled'); } catch (Throwable $e) {}
                TargetSignatureIndexedPattern::insert($signaturePatterns);
                $signaturePatterns = [];
            }
        }

        // Flush any remaining batched rows
        if (!empty($signaturePatterns)) {
            TargetSignatureIndexedPattern::insert($signaturePatterns);
        }
        $durationMs = (int) round((microtime(true) - $fillStart) * 1000);
        try { Log::info($target->name . '/' . ((string)$targetPattern->pattern->template) . ' : fills_completed=' . $count . ' candidates=' . ($candCount ?? 0) . ' duration_ms=' . $durationMs); } catch (Throwable $e) {}
        // Dispatch expansion; run synchronously when no queue is configured
        $queue = config('search.queue');
        if (empty($queue)) {
            // Run inline (synchronously)
            ExpandSignatureIndexedPatternsJob::dispatchSync($targetPattern->id);
        } else {
            // Queue asynchronously (on the configured queue)
            $dispatch = ExpandSignatureIndexedPatternsJob::dispatch($targetPattern->id);
            $dispatch->onQueue($queue);
        }
    }
}
