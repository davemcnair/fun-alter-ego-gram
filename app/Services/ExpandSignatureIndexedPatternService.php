<?php

namespace App\Services;

use App\Models\AlterEgo;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Models\TokenSignature;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service that expands SignatureIndexedPatterns for a given SourceNamePattern
 * into concrete AlterEgo phrases.
 */
final class ExpandSignatureIndexedPatternService
{
    public function expandWithBuilder(int $sourceNamePatternId, PhraseBuilderService $phraseBuilderService): void
    {
        // Load SNP with parent SourceName and signatureIndexed patterns
        $sourceNamePattern = SourceNamePattern::with(['sourceName','signatureIndexedPatterns', 'pattern'])
            ->find($sourceNamePatternId);
        if (!$sourceNamePattern) return;
        /** @var SourceName $source */
        $source = $sourceNamePattern->sourceName;

        // If already done, skip
        if ($sourceNamePattern->status === 'done') return;

        // Claim the pattern for expansion (reuse existing allowed status)
        $sourceNamePattern->status = 'processing';
        $sourceNamePattern->save();

        // Build slot order from the pattern template for formatting
        $slotOrder = $this->buildSlotOrderFromTemplate((string)$sourceNamePattern->pattern->template);

        $createdCount = 0;
        foreach ($sourceNamePattern->signatureIndexedPatterns as $signatureIndexedPattern) {
            $tokenIdSignaturePairs = $this->parseSignatureIndexedPattern($signatureIndexedPattern->pattern);

            $slotWords = [];
            foreach ($tokenIdSignaturePairs as $pair) {
                $ts = TokenSignature::query()
                    ->where('token_id', $pair['token_id'])
                    ->where('signature', $pair['signature'])
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
                    'signature_indexed_pattern_id' => $signatureIndexedPattern->id,
                    'phrase' => $phrase,
                ],
                [
                    'source_name_id' => $source->id,
                ]
            );
            $createdCount++;
        }

        // Mark as done after expansion
        $sourceNamePattern->status = 'done';
        $sourceNamePattern->save();

        // Update parent SourceName status only (completed when no pending/processing remain)
        try {
            $remaining = SourceNamePattern::where('source_name_id', $source->id)
                ->whereIn('status', ['pending', 'processing'])
                ->count();
            if ($remaining === 0) {
                $source->status = 'completed';
            } else if ($source->status !== 'paused') {
                $source->status = 'running';
            }
            $source->save();
        } catch (Throwable $e) {
            try { Log::warning('Failed to update SourceName status for '.$source->id.': '.$e->getMessage()); } catch (Throwable $e2) {}
        }

        // Optional log
        try { Log::info('Expanded signatureIndexed patterns for SNP '.$sourceNamePattern->id.' => '.$createdCount.' phrase(s).'); } catch (Throwable $e) {}
    }

    /**
     * Parse a signatureIndexedPattern string like "{forename:aadm}{surname:ciinv}" into an ordered list of
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
