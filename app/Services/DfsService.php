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
     * @param array<int,string> $chosenSignatures map: pos => sig
     * @param array<int,string> $chosenTokenIds map: pos => token id
     */
    public function dfs(
        array $patternTokenPositions,
        array $remainingTargetLetterCountsNeeded,
        array $candidateTokenSignaturesByTokenId,
        array $chosenSignatures,
        array $chosenTokenIds
    ): Generator
    {
        if (empty($patternTokenPositions)) {
            if (empty($remainingTargetLetterCountsNeeded)) {
                yield $this->buildSignatureIndexedPattern($chosenSignatures, $chosenTokenIds);
            }
            return;
        }

        $pos = array_key_first($patternTokenPositions);
        $tokenId = $patternTokenPositions[$pos];
        unset($patternTokenPositions[$pos]);
        $candidateTokenSignatures = $candidateTokenSignaturesByTokenId[$tokenId] ?? [];
        if (empty($candidateTokenSignatures)) return; // dead end

        // Build viable indices by scanning candidates: must contain at least one needed letter and be able to fit
        $viableIndices = [];
        $candidates = $candidateTokenSignatures['tokenSignatures'];
        /** @var  TokenSignature $candidate */
        foreach ($candidates as $i => $candidate) {
            $hist = $candidate->letterCounts();
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
            $viableIndices[] = $i;
        }
        // no candidate contains any needed letter
        if (empty($viableIndices)) {
            return;
        }

        foreach ($viableIndices as $i) {
            $candidate = $candidateTokenSignatures['tokenSignatures'][$i] ?? null;
            if ($candidate === null) {
                \Log::info('cant');
                continue;
            }
            $hist = $candidate->letterCounts();
            if ($this->candidateLettersExceedNeededCounts($remainingTargetLetterCountsNeeded, $hist)) {
                continue;
            }
            $nextNeed = $this->subtract($remainingTargetLetterCountsNeeded, $hist);
            // Additional pruning after choosing this candidate using slot-aware union (accounts for repeated tokens)
            $slotPrecomputed = [];
            foreach ($patternTokenPositions as $remainingToken) {
                if (isset($candidateTokenSignaturesByTokenId[$remainingToken])) {
                    $slotPrecomputed[] = $candidateTokenSignaturesByTokenId[$remainingToken];
                }
            }
            if (!$this->unionCanFill($slotPrecomputed, $nextNeed)) {
                continue;
            }
            $nextChosenSignatures = $chosenSignatures;
            $nextChosenSignatures[$pos] = $candidate->id; //signature->signature;
            $nextChosenTokenIds = $chosenTokenIds;
            $nextChosenTokenIds[$pos] = $tokenId;
            yield from $this->dfs(
                $patternTokenPositions,
                $nextNeed,
                $candidateTokenSignaturesByTokenId,
                $nextChosenSignatures,
                $nextChosenTokenIds
            );
        }
    }

    private function buildSignatureIndexedPattern(
        array $chosenSignatures,
        array $chosenTokenIds
    ): string
    {
        ksort($chosenSignatures);
        ksort($chosenTokenIds);
        $parts = [];
        foreach ($chosenSignatures as $pos => $tokenSignatureId) {
            $tokenId = (string)($chosenTokenIds[$pos] ?? '');
            $parts[] = '{' . $tokenId . ':' . $tokenSignatureId . '}';
        }
        return implode('', $parts);
    }

}
