<?php

namespace App\Services;

use App\Dtos\PhraseDto;
use App\Dtos\WordDto;
use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Models\AlterEgo;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\TargetSignaturedPattern;
use App\Models\TargetTokenSignatureWord;
use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpandSignaturedPatternService
{
    public function expandSignaturedPatterns(int $targetPatternId): void
    {
        $targetPattern = TargetPattern::with([
            'signaturedPatterns.targetTokenSignatures.tokenSignature.token',
            'signaturedPatterns.targetTokenSignatures.tokenSignature.words'
        ])->find($targetPatternId);

        if (!$targetPattern) {
            return;
        }

        /** @var Target $target */
        $target = $targetPattern->target;

        if ($targetPattern->status === TargetPatternStatus::FILLED) {
            return;
        }

        $targetPattern->status = TargetPatternStatus::PROCESSING;
        $targetPattern->save();

        $timer = Metrics::start('expand_duration_ms', [
            'target_id' => $target->id,
            'target_pattern_id' => $targetPattern->id,
        ]);

        $createdCount = 0;

        /** @var TargetSignaturedPattern $targetSignaturedPattern */
        foreach ($targetPattern->signaturedPatterns as $targetSignaturedPattern) {
            // Build array of word choices per position
            $wordsByPosition = [];

            foreach ($targetSignaturedPattern->targetTokenSignatures as $targetTokenSignature) {
                $position = $targetTokenSignature->pivot->position;
                $tokenName = $targetTokenSignature->tokenSignature->token->name;

                $wordsByPosition[$position] = [];

                foreach ($targetTokenSignature->tokenSignature->words as $tokenSignatureWord) {
                    $wordsByPosition[$position][] = new WordDto(
                        $tokenName,
                        $tokenSignatureWord->word,
                        $tokenSignatureWord->list_type,
                        $tokenSignatureWord->is_promotable,
                        $tokenSignatureWord->id,
                        $tokenSignatureWord->is_deferred,
                        true,
                    );
                    if ($unusedTargetTokenSignatureWord = TargetTokenSignatureWord::where('target_id',$target->id)
                        ->where('token_signature_word_id',$tokenSignatureWord->id)
                        ->where('usedInPhrase', false)->first()) {
                        $unusedTargetTokenSignatureWord->usedInPhrase = true;
                        $unusedTargetTokenSignatureWord->save();
                    }
                }
            }

            // Sort by position to ensure correct order
            ksort($wordsByPosition);

            // Generate all combinations of words (one from each position)
            $combinations = $this->generateWordCombinations($wordsByPosition);

            foreach ($combinations as $wordCombination) {
                $phrase = PhraseDto::fromWords($wordCombination);

                // Persist as AlterEgo (idempotent)
                AlterEgo::firstOrCreate(
                    [
                        'target_signatured_pattern_id' => $targetSignaturedPattern->id,
                        'phrase' => $phrase->phrase,
                    ],
                    [
                        'isFun' => $phrase->isFun,
                        'hasBoring' => $phrase->hasBoring,
                        'starred' => $phrase->starred,
                    ]
                );

                $createdCount++;
            }
        }

        // Mark as done after expansion
        $targetPattern->status = TargetPatternStatus::FILLED;
        $targetPattern->finished_at = now();
        $targetPattern->save();

        // Update parent Target status
        try {
            $remaining = TargetPattern::where('target_id', $target->id)
                ->whereIn('status', [
                    TargetPatternStatus::PROCESSING,
                    TargetPatternStatus::DEFERRED,
                ])
                ->count();

            $target->status = $remaining === 0
                ? TargetStatus::processed
                : TargetStatus::processing;
            $target->save();
        } catch (Throwable $e) {
            Log::warning('Failed to update Target status for '.$target->id.': '.$e->getMessage());
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

        try {
            $target->last_processed_matches_at = now();
            $target->save();
        } catch (Throwable $e) {
            Log::warning('Failed updating last_processed_matches_at for target '.$target->id.': '.$e->getMessage());
        }
    }

    /**
     * Generate all combinations of words, taking one word from each position.
     *
     * @param array<int, array<WordDto>> $wordsByPosition
     * @return array<array<WordDto>>
     */
    private function generateWordCombinations(array $wordsByPosition): array
    {
        if (empty($wordsByPosition)) {
            return [];
        }

        // Get positions in order
        $positions = array_keys($wordsByPosition);
        $combinations = [[]];

        foreach ($positions as $position) {
            $newCombinations = [];

            foreach ($combinations as $combination) {
                foreach ($wordsByPosition[$position] as $word) {
                    $newCombination = $combination;
                    $newCombination[] = $word;
                    $newCombinations[] = $newCombination;
                }
            }

            $combinations = $newCombinations;
        }

        return $combinations;
    }
}
