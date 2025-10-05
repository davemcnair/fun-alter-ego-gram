<?php

namespace App\Services;

use App\Enums\TargetPatternStatus;
use App\Jobs\ExpandSignaturedPatternsJob;
use App\Models\Pattern;
use App\Models\TargetPattern;
use App\Traits\HelpsMatchWords;
use App\Traits\ScalesJobs;
use App\Support\Metrics;
use Illuminate\Support\Facades\DB;
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

        if ($targetPattern->status !== TargetPatternStatus::PENDING) {
            return;
        }

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
        $targetLetterCountsNeeded = $targetPattern->target->signature->letterCounts();

        // Rarity-first slot ordering: order by ascending candidate count per token
        $tokenSignatureCountsByTokenId = [];
        foreach ($targetPattern->target->tokenSignatures as $targetTokenSignature) {
            $token_id = (int)$targetTokenSignature->tokenSignature->token_id;
            $tokenSignatureCountsByTokenId[$token_id] = ($tokenSignatureCountsByTokenId[$token_id] ?? 0) + 1;
        }
        $tokenPositionsOrderedBySignatureCount = $patternTokenPositions; // copy
        uasort($tokenPositionsOrderedBySignatureCount, function($aTokenId, $bTokenId) use ($tokenSignatureCountsByTokenId) {
            $ac = $tokenSignatureCountsByTokenId[$aTokenId] ?? PHP_INT_MAX;
            $bc = $tokenSignatureCountsByTokenId[$bTokenId] ?? PHP_INT_MAX;
            if ($ac === $bc) return 0;
            return $ac < $bc ? -1 : 1;
        });

        $signaturePatterns = [];
        $count = 0;
        foreach ($this->signatureFillService->generateSignaturedPatterns(
            $targetLetterCountsNeeded,
            $tokenPositionsOrderedBySignatureCount,
            $targetPattern->target->tokenSignatures
        ) as $orderedChosenTargetTokenSignatureIds) {
            $signaturePatterns[] = [
                'target_pattern_id' => $targetPattern->id,
                'target_token_signature_ids' => $orderedChosenTargetTokenSignatureIds,
            ];
            $count++;
            if ($count % 1000 === 0) {
                try {
                    Log::info($targetPattern->target->name . '/' . ((string)$targetPattern->pattern->template) . ' :' . $count . ' filled');
                } catch (Throwable $e) {}
                $this->bulkInsert($signaturePatterns);
                $signaturePatterns = [];
            }
        }

        // Flush any remaining batched rows
        if (!empty($signaturePatterns)) {
            $this->bulkInsert($signaturePatterns);
        }
        Metrics::counter('signatured_patterns_generated', $count, [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
        ]);
        $durationMs = Metrics::end($timer, [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
            'generated' => $count,
        ]);
        // todo: save interim elapsed
        Log::info('fill.complete', [
            'target_id' => $targetPattern->target_id,
            'target_pattern_id' => $targetPattern->id,
            'generated' => $count,
            'duration_ms' => $durationMs,
        ]);
        // Dispatch expansion; scale based on queue configuration
        $this->scaledDispatch(ExpandSignaturedPatternsJob::class, $targetPattern->id);
        // Note: finished_at and elapsed_ms are now computed centrally in
        // ExpandSignaturedPatternService after expansion completes so
        // that both queued and inline execution paths record timing.
    }

    public function bulkInsert($signaturePatterns)
    {

        DB::transaction(function () use ($signaturePatterns) {
            $overallStart = microtime(true);
            $createdCount = 0;

            $parentInsertTime = 0;
            $pivotInsertTime = 0;

            foreach ($signaturePatterns as $pattern) {
                // Time parent insert
                $parentStart = microtime(true);
                $parentId = DB::table('target_signatured_patterns')->insertGetId([
                    'target_pattern_id' => $pattern['target_pattern_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $parentInsertTime += (microtime(true) - $parentStart);

                // Prepare pivot records for this parent
                $pivotRecords = [];
                foreach ($pattern['target_token_signature_ids'] as $position => $tokenSignatureId) {
                    $pivotRecords[] = [
                        'target_signatured_pattern_id' => $parentId,
                        'target_token_signature_id' => $tokenSignatureId,
                        'position' => $position,
                    ];
                }

                // Time pivot insert
                if (!empty($pivotRecords)) {
                    $pivotStart = microtime(true);
                    DB::table('target_signatured_pattern_target_token_signature')
                        ->insert($pivotRecords);
                    $pivotInsertTime += (microtime(true) - $pivotStart);
                }

                $createdCount++;
            }

            $overallTime = microtime(true) - $overallStart;

            Log::info('Bulk insert completed', [
                'total_patterns' => $createdCount,
                'overall_time_ms' => round($overallTime * 1000, 2),
                'parent_insert_time_ms' => round($parentInsertTime * 1000, 2),
                'pivot_insert_time_ms' => round($pivotInsertTime * 1000, 2),
                'avg_parent_ms' => round(($parentInsertTime / $createdCount) * 1000, 2),
                'avg_pivot_ms' => round(($pivotInsertTime / $createdCount) * 1000, 2),
            ]);

            return $createdCount;
        });
    }
}
