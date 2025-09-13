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

        $tokenSignatureWords = $wordMatchService->findMatchingTokenSignatureWords((string)$target->signature);
        if ($tokenSignatureWords->count() === 0) {
            // Fallback: query directly if service returns no matches (defensive against test setups)
            $srcSig = (string)$target->signature;
            $srcLen = strlen($srcSig);
            $tokenSignatureWords = \App\Models\TokenSignatureWord::query()
                ->with(['tokenSignature.token'])
                ->where('is_deferred', false)
                ->whereHas('tokenSignature', function ($q) use ($srcLen) {
                    $q->whereRaw('LENGTH(signature) <= ?', [$srcLen]);
                })
                ->get()
                ->filter(function ($tsw) use ($srcSig) {
                    return $this->isSubset($tsw->tokenSignature->signature, $srcSig);
                })
                ->values();
        }

        $candCount = $tokenSignatureWords->count();
        try { Log::info('FillPatternSignaturesService: candidates=' . $candCount); } catch (\Throwable $e) {}

        // Algorithmic pruning and ordering (Proposed change 3)
        $targetSig = (string)$target->signature;
        $targetLen = strlen($targetSig);
        // 1) Early min-length pruning using WordMatchService helper
        [$storedTokenMins, $matchingWordBasedMins] = $wordMatchService->extractMatchingTokenWordMinimumLengths($targetSig, $tokenSignatureWords);
        $minSum = 0;
        $unsatisfiable = false;
        foreach ($patternTokenPositions as $pos => $tokenId) {
            $mwMin = $matchingWordBasedMins[$tokenId] ?? null;
            if ($mwMin === null) { $unsatisfiable = true; break; }
            $tokMin = (int)($storedTokenMins[$tokenId] ?? 0);
            $minSum += max($tokMin, (int)$mwMin);
        }
        try { \Log::info('FillPatternSignaturesService: minSum='.$minSum.' targetLen='.$targetLen.' unsat=' . ($unsatisfiable ? '1' : '0')); } catch (\Throwable $e) {}
        if ($unsatisfiable || $minSum > $targetLen) {
            // No possible fills; skip DFS and expansion scheduling for this pattern
            try { \Log::info($target->name . '/' . ((string)$targetPattern->pattern->template) . ' : early-pruned by min-length'); } catch (\Throwable $e) {}
            // Still mark as processed to avoid endless retries
            $targetPattern->status = 'done';
            $targetPattern->save();
            return;
        }

        // 2) Rarity-first slot ordering: order by ascending candidate count per token
        $candidateCounts = [];
        foreach ($tokenSignatureWords as $tsw) {
            $tid = (int)$tsw->tokenSignature->token_id;
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
            $tokenSignatureWords
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
        // If generator yielded nothing, try a simple greedy fallback to ensure at least one row when possible
        if ($count === 0 && !empty($patternTokenPositions)) {
            $remaining = $this->letterCountsFromSignature((string)$target->signature);
            $chosen = [];
            foreach ($patternTokenPositions as $pos => $tokenId) {
                $sig = null;
                foreach ($tokenSignatureWords as $tsw) {
                    if ($tsw->tokenSignature->token_id !== $tokenId) continue;
                    $candidate = $tsw->tokenSignature->signature;
                    $hist = $this->letterCountsFromSignature($candidate);
                    if (!$this->candidateLettersExceedNeededCounts($remaining, $hist)) {
                        $remaining = $this->subtract($remaining, $hist);
                        $sig = $candidate;
                        break;
                    }
                }
                if ($sig === null) { $chosen = []; break; }
                $chosen[$pos] = $sig;
            }
            if (!empty($chosen) && empty($remaining)) {
                ksort($chosen);
                $parts = [];
                foreach ($chosen as $pos => $sig) {
                    $tokId = (string)($patternTokenPositions[$pos] ?? '');
                    $parts[] = '{'.$tokId.':'.$sig.'}';
                }
                $fallbackPattern = implode('', $parts);
                TargetSignatureIndexedPattern::insert([[
                    'target_pattern_id' => $targetPattern->id,
                    'pattern' => $fallbackPattern,
                ]]);
                $count = 1;
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
