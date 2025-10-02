<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Models\AlterEgo;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\TargetTokenSignature;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service that expands SignatureIndexedPatterns for a given TargetPattern
 * into concrete AlterEgo phrases.
 */
final class ExpandSignatureIndexedPatternService
{
    public function __construct(protected PhraseBuilderService $phraseBuilderService)
    {}

    public function expandWithBuilder(int $targetPatternId): void
    {
        $targetPattern = TargetPattern::with(['signatureIndexedPatterns'])
            ->find($targetPatternId);
        if (!$targetPattern) {
            return;
        }
        /** @var Target $target */
        $target = $targetPattern->target;

        // If already done, skip
        if ($targetPattern->status === TargetPatternStatus::FILLED) return;

        // Claim the pattern for expansion
        $targetPattern->status = TargetPatternStatus::PROCESSING;
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
            $targetTokenSignatureIds = $this->parseSignatureIndexedPattern($signatureIndexedPattern->pattern);

            $slotWords = [];
            foreach ($targetTokenSignatureIds as $target_token_signature_id) {
                $tts = TargetTokenSignature::find($target_token_signature_id);

                // Prefer 'fun' list, then 'ok', then any other; only non-deferred words
                $nonDeferredWords = $tts->tokenSignature->words()
                    ->where('is_deferred', false)
                    ->orderByRaw("CASE list_type WHEN 'fun' THEN 0 WHEN 'ok' THEN 1 ELSE 2 END")
                    ->pluck('word');

                $slotWords[] = $nonDeferredWords;
            }

            $phrase = $this->phraseBuilderService->formatPhraseBySlots($slotWords, $slotOrder, false);

            // Persist as AlterEgo (idempotent)
            $alterEgo = AlterEgo::where('target_signature_indexed_pattern_id', $signatureIndexedPattern->id)
                ->where('phrase',$phrase)
                ->first();
            if (!$alterEgo) {
                $alterEgo = new AlterEgo();
                $alterEgo->target_signature_indexed_pattern_id = $signatureIndexedPattern->id;
                $alterEgo->phrase = $phrase;
                $alterEgo->save();
            }
            $createdCount++;
        }

        // Mark as done after expansion and record timing
        $targetPattern->status = TargetPatternStatus::FILLED;
        // Set finished_at now; elapsed will be set using high-resolution timer below
        $finished = now();
        $targetPattern->finished_at = $finished;
        // Persist status/start/finish immediately
        $targetPattern->save();

        // Update parent Target status only (completed when no pending/processing remain)
        try {
            $remaining = TargetPattern::where('target_id', $target->id)
                ->whereIn('status', [
                    TargetPatternStatus::PROCESSING,
                    TargetPatternStatus::DEFERRED,
                ])
                ->count();
            if ($remaining === 0) {
                $target->status = TargetStatus::processed;
            } else {
                $target->status = TargetStatus::processing;
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
        // Use high-resolution timer to set elapsed_ms reliably even when DB timestamps lack sub-second precision
        try {
            $dur = (int) $durationMs;
            if ($dur > 0 && $dur <= 3_600_000) {
                $targetPattern->elapsed_ms = $dur;
                $targetPattern->save();
            }
        } catch (Throwable $e) { /* ignore */ }
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
        // Expect patterns like {12}{45}
        if (preg_match_all('/\{([0-9]+)\}/i', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $out[] = (int)$match[1];
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
