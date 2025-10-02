<?php
namespace App\Services;

use App\Models\TokenSignature;
use App\Traits\HelpsMatchWords;
use Generator;

final class DfsService
{
    use HelpsMatchWords;

    /**
     * Depth-first search (DFS) over slots:
     *      collect chosen signatures per slot
     *      emit pattern string when exact cover achieved
     *
     * @param array<int, string> $patternTokenPositions
     * @param array<string,int> $remainingTargetLetterCountsNeeded
     * @param array<int,string> $chosenTargetTokenSignatureIds map: pos => sig
     * @param array<int,string> $chosenTokenIds map: pos => token id
     */
    public function dfs(
        array $patternTokenPositions,
        array $remainingTargetLetterCountsNeeded,
        array $candidateTokenSignaturesByTokenId,
        array $chosenTargetTokenSignatureIds,
        array $chosenTokenIds
    ): Generator
    {
        if (empty($patternTokenPositions)) {
            if (empty($remainingTargetLetterCountsNeeded)) {
                yield $this->buildSignatureIndexedPattern($chosenTargetTokenSignatureIds, $chosenTokenIds);
            }
            return;
        }

        $pos = array_key_first($patternTokenPositions);
        $tokenId = $patternTokenPositions[$pos];
        unset($patternTokenPositions[$pos]);
        $nextTokenTargetTokenSignatures = $candidateTokenSignaturesByTokenId[$tokenId] ?? [];
        if (empty($nextTokenTargetTokenSignatures)) {
            return; // dead end
        }

        // Build viable indices by scanning candidates: must contain at least one needed letter and be able to fit
        $viableIndices = [];
        $nextTokenTargetTokenSignatures = $nextTokenTargetTokenSignatures['targetTokenSignatures'];
        /** @var  TokenSignature $targetTokenSignature */
        foreach ($nextTokenTargetTokenSignatures as $i => $targetTokenSignature) {
            $hist = $targetTokenSignature->tokenSignature->signature->letterCounts();
            // Must share at least one needed letter
            $shares = false;
            foreach ($remainingTargetLetterCountsNeeded as $ch => $n) {
                if (isset($hist[$ch])) {
                    $shares = true;
                    break;
                }
            }
            if (!$shares) {
                continue;
            }
            // Must not exceed needed letterCounts
            if ($this->candidateLettersExceedNeededCounts($remainingTargetLetterCountsNeeded, $hist)) {
                continue;
            }
            $viableIndices[$i] = ['hist' => $hist, 'id'=> $targetTokenSignature->id];
        }
        // no candidate contains any needed letter
        if (empty($viableIndices)) {
            return;
        }

        foreach ($viableIndices as $i => $candidate) {
//            $targetTokenSignature = $nextTokenTargetTokenSignatures['targetTokenSignatures'][$i];
//            if ($this->candidateLettersExceedNeededCounts($remainingTargetLetterCountsNeeded, $hist)) {
//                \Log::info('cant');
//                continue;
//            }
            $nextNeededLetters = $this->subtract($remainingTargetLetterCountsNeeded, $candidate['hist']);
            // Additional pruning after choosing this candidate using slot-aware union (accounts for repeated tokens)
            $tokensAvailableLetters = [];
            foreach ($patternTokenPositions as $remainingToken) {
                if (isset($candidateTokenSignaturesByTokenId[$remainingToken])) {
                    $tokensAvailableLetters[] = $candidateTokenSignaturesByTokenId[$remainingToken]['maxLetterCounts'];
                }
            }
            if (!$this->canAssembleFromTokens($tokensAvailableLetters, $nextNeededLetters)) {
                continue;
            }
            $nextChosenTargetTokenSignatureIds = $chosenTargetTokenSignatureIds;
            $nextChosenTargetTokenSignatureIds[$pos] = $candidate['id'];
            $nextChosenTokenIds = $chosenTokenIds;
            $nextChosenTokenIds[$pos] = $tokenId;
            yield from $this->dfs(
                $patternTokenPositions,
                $nextNeededLetters,
                $candidateTokenSignaturesByTokenId,
                $nextChosenTargetTokenSignatureIds,
                $nextChosenTokenIds
            );
        }
    }

    private function buildSignatureIndexedPattern(
        array $chosenTargetTokenSignatureIds,
        array $chosenPosTokenIds
    ): string
    {
        ksort($chosenTargetTokenSignatureIds);
        ksort($chosenPosTokenIds);
        $parts = [];
        foreach ($chosenTargetTokenSignatureIds as $pos => $targetTokenSignatureId) {
            $tokenId = (string)($chosenPosTokenIds[$pos] ?? '');
            $parts[] = '{' . $tokenId . ':' . $targetTokenSignatureId . '}';
        }
        return implode('', $parts);
    }

}
