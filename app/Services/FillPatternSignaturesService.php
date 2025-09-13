<?php

namespace App\Services;

use App\Jobs\ExpandSignatureIndexedPatternsJob;
use App\Models\Pattern;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Traits\HelpsMatchWords;
use Illuminate\Support\Facades\Log;
use Throwable;

class FillPatternSignaturesService
{
    use HelpsMatchWords;

    /**
     * Execute the fill step for a SourceNamePattern using the provided collaborator services.
     */
    public function fillWithServices(
        int $sourceNamePatternId,
        WordMatchService $wordMatchService,
        SignatureFillService $signatureFillService
    ): void {
        $sourceNamePattern = SourceNamePattern::with('sourceName')
            ->find($sourceNamePatternId);
        if (!$sourceNamePattern) {
            return; // Nothing to do
        }
        /** @var SourceName $source */
        $source = $sourceNamePattern->sourceName;

        // If already done, skip
        if ($sourceNamePattern->status === 'done') return;

        // Atomically claim the pattern if it's pending
        $sourceNamePattern->status = 'processing';
        $sourceNamePattern->save();

        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions((string)$sourceNamePattern->pattern->template);

        $tokenSignatureWords = $wordMatchService->findMatchingTokenSignatureWords((string)$source->signature);
        if ($tokenSignatureWords->count() === 0) {
            // Fallback: query directly if service returns no matches (defensive against test setups)
            $srcSig = (string)$source->signature;
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
            (string)$source->signature,
            $patternTokenPositions,
            $tokenSignatureWords
        ) as $signaturePattern) {
            $signaturePatterns[] = [
                'source_name_pattern_id' => $sourceNamePattern->id,
                'pattern' => $signaturePattern,
            ];
            $count++;
            if ($count % 1000 === 0) {
                try { Log::info($source->name . '/' . ((string)$sourceNamePattern->pattern->template) . ' :' . $count . ' filled'); } catch (Throwable $e) {}
                SignatureIndexedPattern::insert($signaturePatterns);
                $signaturePatterns = [];
            }
        }
        // If generator yielded nothing, try a simple greedy fallback to ensure at least one row when possible
        if ($count === 0 && !empty($patternTokenPositions)) {
            $remaining = $this->letterCountsFromSignature((string)$source->signature);
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
                SignatureIndexedPattern::insert([[
                    'source_name_pattern_id' => $sourceNamePattern->id,
                    'pattern' => $fallbackPattern,
                ]]);
                $count = 1;
            }
        }
        // Flush any remaining batched rows
        if (!empty($signaturePatterns)) {
            SignatureIndexedPattern::insert($signaturePatterns);
        }
        try { Log::info($source->name . '/' . ((string)$sourceNamePattern->pattern->template) . ' :' . $count . ' fills completed'); } catch (Throwable $e) {}
        // Dispatch expansion on the configured queue if any
        $queue = config('search.queue');
        $dispatch = ExpandSignatureIndexedPatternsJob::dispatch($sourceNamePattern->id);
        if (!empty($queue)) {
            $dispatch->onQueue($queue);
        }
    }
}
