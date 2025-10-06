<?php
namespace App\Services;

use App\Models\TargetTokenSignature;
use App\Models\TokenSignature;
use App\Traits\HelpsMatchWords;
use Generator;

final class DfsService
{
    use HelpsMatchWords;

    /**
     * Depth-first search (DFS) over slots:
     *      collect chosen signatures per slot
     *      emit chosen target_token_signature_ids when exact cover achieved
     *
     * @param array<int, string> $remainingPatternTokenPositions
     * @param array<string,int> $remainingTargetLetterCountsNeeded
     * @param array<int, array{
     *     targetTokenSignatures: array<int, TargetTokenSignature>,
     *     precomputedLetterCounts: array<int, array<string, int>>,
     *     maxLetterCounts: array<string, int>
     * }> $availableTokenSignaturesByTokenId
 * @param array<int,int> $selectedTargetTokenSignatureIds map: pos => sig
     */
    public function dfs(
        array $remainingPatternTokenPositions, // slots remaining
        array $remainingTargetLetterCountsNeeded, // letters left to use
        array $availableTokenSignaturesByTokenId, // available "words"
        array $selectedTargetTokenSignatureIds, // selected "words"
    ): Generator
    {
        // Base case: all slots filled
        if (empty($remainingPatternTokenPositions)) {
            // Exact cover achieved?
            if (empty($remainingTargetLetterCountsNeeded)) {
                // Yes! - a viable signature-filled pattern
                // todo: should ksort happen elsewhere?
                // sort by position for consistent ordering
           //     ksort($selectedTargetTokenSignatureIds);
                yield $selectedTargetTokenSignatureIds;
            }
            return;
        }
        // select next slot position to fill
        $pos = array_key_first($remainingPatternTokenPositions);
        $tokenId = $remainingPatternTokenPositions[$pos];
        unset($remainingPatternTokenPositions[$pos]);

        $nextTokenTargetTokenSignatures = $availableTokenSignaturesByTokenId[$tokenId] ?? [];
        if (empty($nextTokenTargetTokenSignatures)) {
            return; // dead end
        }

        // Build viable indices by scanning candidates: must contain at least one needed letter and be able to fit
        $viableIndices = [];
        $precomputedLetterCounts = $nextTokenTargetTokenSignatures['precomputedLetterCounts'];
        $nextTokenTargetTokenSignatures = $nextTokenTargetTokenSignatures['targetTokenSignatures'];

        /** @var  TokenSignature $targetTokenSignature */
        foreach ($nextTokenTargetTokenSignatures as $i => $targetTokenSignature) {
            $hist = $precomputedLetterCounts[$i];

            // Must share at least one needed letter
            if (!array_intersect_key($remainingTargetLetterCountsNeeded, $hist)) {
                continue;
            }

            // Must not exceed needed letterCounts
            if ($this->candidateLettersExceedNeededCounts($remainingTargetLetterCountsNeeded, $hist)) {
                continue;
            }
            $viableIndices[$targetTokenSignature->id] = $hist;
        }
        // no candidate contains any needed letter
        if (empty($viableIndices)) {
            return;
        }

        // Pre-compute available letters from remaining tokens (optimization)
        $tokensAvailableLetters = [];
        foreach ($remainingPatternTokenPositions as $remainingToken) {
            if (isset($availableTokenSignaturesByTokenId[$remainingToken]['maxLetterCounts'])) {
                $tokensAvailableLetters[] = $availableTokenSignaturesByTokenId[$remainingToken]['maxLetterCounts'];
            }
        }

        foreach ($viableIndices as $targetTokenSignatureId => $hist) {
            $nextNeededLetters = $this->subtract($remainingTargetLetterCountsNeeded, $hist);
            // Additional pruning after choosing this candidate using slot-aware union (accounts for repeated tokens)
            if (!$this->canFillFromAvailableLetterPools($tokensAvailableLetters, $nextNeededLetters)) {
                continue;
            }
            $nextChosenTargetTokenSignatureIds = $selectedTargetTokenSignatureIds;
            $nextChosenTargetTokenSignatureIds[$pos] = $targetTokenSignatureId;
            yield from $this->dfs(
                $remainingPatternTokenPositions,
                $nextNeededLetters,
                $availableTokenSignaturesByTokenId,
                $nextChosenTargetTokenSignatureIds,
            );
        }
    }

}
