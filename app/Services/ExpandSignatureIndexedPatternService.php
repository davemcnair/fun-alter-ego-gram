<?php

namespace App\Services;

use App\Models\AlterEgo;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\TokenSignature;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service that expands SignatureIndexedPatterns for a given TargetPattern
 * into concrete AlterEgo phrases.
 */
final class ExpandSignatureIndexedPatternService
{
    public function expandWithBuilder(int $targetPatternId, PhraseBuilderService $phraseBuilderService): void
    {
        // Load TP with parent Target and signatureIndexed patterns
        $targetPattern = TargetPattern::with(['target','signatureIndexedPatterns', 'pattern'])
            ->find($targetPatternId);
        if (!$targetPattern) return;
        /** @var Target $target */
        $target = $targetPattern->target;

        // If already done, skip
        if ($targetPattern->status === 'done') return;

        // Claim the pattern for expansion (reuse existing allowed status)
        $targetPattern->status = 'processing';
        $targetPattern->save();

        // Build slot order from the pattern template for formatting
        $slotOrder = $this->buildSlotOrderFromTemplate((string)$targetPattern->pattern->template);

        $timer = Metrics::start('expand_duration_ms', [
            'target_id' => $target->id,
            'target_pattern_id' => $targetPattern->id,
        ]);
        Log::info('expand.start', [
            'target_id' => $target->id,
            'target_pattern_id' => $targetPattern->id,
            'template' => (string)($targetPattern->pattern->template ?? ''),
        ]);

        $createdCount = 0;
        foreach ($targetPattern->signatureIndexedPatterns as $signatureIndexedPattern) {
            $tokenIdSignaturePairs = $this->parseSignatureIndexedPattern($signatureIndexedPattern->pattern);

            $slotWords = [];
            foreach ($tokenIdSignaturePairs as $pair) {
                $ts = TokenSignature::query()
                    ->where('token_id', $pair['token_id'])
                    ->whereHas('signature', function ($q) use ($pair) {
                        $q->where('signature', $pair['signature']);
                    })
                    ->first();

                if ($ts) {
                    // Prefer 'fun' list, then 'ok', then any other; only non-deferred words
                    $nonDeferredWords = $ts->words()
                        ->where('is_deferred', false)
                        ->orderByRaw("CASE list_type WHEN 'fun' THEN 0 WHEN 'ok' THEN 1 ELSE 2 END")
                        ->pluck('word');
                } else {
                    $nonDeferredWords = collect();
                }
                $slotWords[] = $nonDeferredWords;
            }

            $phrase = $phraseBuilderService->formatPhraseBySlots($slotWords, $slotOrder, false);

            // Persist as AlterEgo (idempotent)
            AlterEgo::firstOrCreate(
                [
                    'target_signature_indexed_pattern_id' => $signatureIndexedPattern->id,
                    'phrase' => $phrase,
                ]
            );
            $createdCount++;
        }

        // Mark as done after expansion
        $targetPattern->status = 'done';
        $targetPattern->save();

        // Update parent Target status only (completed when no pending/processing remain)
        try {
            $remaining = TargetPattern::where('target_id', $target->id)
                ->whereIn('status', ['pending', 'processing'])
                ->count();
            if ($remaining === 0) {
                $target->status = 'completed';
            } else if ($target->status !== 'paused') {
                $target->status = 'running';
            }
            $target->save();
        } catch (Throwable $e) {
            try { Log::warning('Failed to update Target status for '.$target->id.': '.$e->getMessage()); } catch (Throwable $e2) {}
        }

        Metrics::counter('phrases_generated', $createdCount, [
            'target_id' => $target->id,
            'target_pattern_id' => $targetPattern->id,
        ]);
        $durationMs = Metrics::end($timer, [
            'target_id' => $target->id,
            'target_pattern_id' => $targetPattern->id,
            'generated' => $createdCount,
        ]);
        Log::info('expand.complete', [
            'target_id' => $target->id,
            'target_pattern_id' => $targetPattern->id,
            'generated' => $createdCount,
            'duration_ms' => $durationMs,
        ]);

        // Processing watermark: mark the time we completed processing new matches for this target
        try {
            $target->last_processed_matches_at = now();
            $target->save();
        } catch (Throwable $e) {
            try { Log::warning('Failed updating last_processed_matches_at for target '.$target->id.': '.$e->getMessage()); } catch (Throwable $e2) {}
        }
    }

    /**
     * Parse a targetSignatureIndexedPattern string like "{forename:aadm}{surname:ciinv}" into an ordered list of
     * [ ['token_id'=>1,'signature'=>'aadm'], ... ]
     * @return array<int,array{token_id:int,signature:string}>
     */
    private function parseSignatureIndexedPattern(string $s): array
    {
        $out = [];
        // Expect patterns like {1:aadm}{4:ciinv}
        if (preg_match_all('/\{([0-9]+):([a-z]+)\}/i', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $tokenId = (int)$match[1];
                $signature = strtolower($match[2]);
                $out[] = [ 'token_id' => $tokenId, 'signature' => $signature ];
            }
        }
        return $out;
    }

    /**
     * Build a slot order array from a pattern template, suitable for PhraseBuilderService.
     * Example input: "{title}{forename}{surname:2}" ->
     *   [ ['name'=>'title','pos'=>0], ['name'=>'forename','pos'=>1], ['name'=>'surname','pos'=>2], ['name'=>'surname','pos'=>3] ]
     * @return array<int,array{name:string,pos:int}>
     */
    private function buildSlotOrderFromTemplate(string $template): array
    {
        $slotOrder = [];
        $pos = 0;
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $template, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = strtolower($match[1]);
                $count = isset($match[2]) && ctype_digit($match[2]) ? max(1, (int)$match[2]) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $slotOrder[] = ['name' => $name, 'pos' => $pos++];
                }
            }
        }
        return $slotOrder;
    }
}
