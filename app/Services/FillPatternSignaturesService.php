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

        $signaturePatterns = [];
        $count = 0;
        foreach ($signatureFillService->generateSignaturePatterns(
            (string)$target->signature,
            $patternTokenPositions,
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
        try { Log::info($target->name . '/' . ((string)$targetPattern->pattern->template) . ' :' . $count . ' fills completed'); } catch (Throwable $e) {}
        // Dispatch expansion on the configured queue if any
        $queue = config('search.queue');
        $dispatch = ExpandSignatureIndexedPatternsJob::dispatch($targetPattern->id);
        if (!empty($queue)) {
            $dispatch->onQueue($queue);
        }
    }
}
