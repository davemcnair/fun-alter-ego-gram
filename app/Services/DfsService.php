<?php
namespace App\Services;

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
        array $candidateSignaturesByTokenId,
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
        $candidates = $candidateSignaturesByTokenId[$tokenId] ?? [];
        if (empty($candidates)) return; // dead end

        // Build viable indices by scanning candidates: must contain at least one needed letter and be able to fit
        $viableIndices = [];
        $all = (array)($candidates['signatures'] ?? []);
        foreach ($all as $i => $candidate) {
            $hist = (array)($candidate['hist'] ?? []);
            // Must share at least one needed letter
            $shares = false;
            foreach ($remainingTargetLetterCountsNeeded as $ch => $n) {
                if (isset($hist[$ch])) { $shares = true; break; }
            }
            if (!$shares) continue;
            // Must not exceed needed counts
            if ($this->candidateLettersExceedNeededCounts($remainingTargetLetterCountsNeeded, $hist)) continue;
            $viableIndices[] = $i;
        }
        if (empty($viableIndices)) return; // no candidate contains any needed letter

        // Optional: sort viable indices by candidate length then signature for determinism
        usort($viableIndices, function($a, $b) use ($candidates){
            $A = $candidates['signatures'][$a] ?? null; $B = $candidates['signatures'][$b] ?? null;
            if ($A === null || $B === null) return 0;
            if ($A['len'] === $B['len']) return $A['signature'] <=> $B['signature'];
            return $A['len'] <=> $B['len'];
        });

        foreach ($viableIndices as $i) {
            $candidate = $candidates['signatures'][$i] ?? null;
            if ($candidate === null) continue;
            $hist = (array)($candidate['hist'] ?? []);
            if ($this->candidateLettersExceedNeededCounts($remainingTargetLetterCountsNeeded, $hist)) continue;
            $nextNeed = $this->subtract($remainingTargetLetterCountsNeeded, $hist);
            // Additional pruning after choosing this candidate using slot-aware union (accounts for repeated tokens)
            $slotPrecomputed = [];
            foreach ($patternTokenPositions as $remPos => $remainingToken) {
                if (isset($candidateSignaturesByTokenId[$remainingToken])) {
                    $slotPrecomputed[] = $candidateSignaturesByTokenId[$remainingToken];
                }
            }
            if (!$this->unionCanFill($slotPrecomputed, $nextNeed)) continue;
            $nextChosenSignatures = $chosenSignatures; $nextChosenSignatures[$pos] = (string)$candidate['signature'];
            $nextChosenTokenIds = $chosenTokenIds; $nextChosenTokenIds[$pos] = $tokenId;
            yield from $this->dfs(
                $patternTokenPositions,
                $nextNeed,
                $candidateSignaturesByTokenId,
                $nextChosenSignatures,
                $nextChosenTokenIds
            );
        }
    }

    private function buildSignatureIndexedPattern(array $chosenSigs, array $chosenTokenIds): string
    {
        ksort($chosenSigs);
        ksort($chosenTokenIds);
        $parts = [];
        foreach ($chosenSigs as $pos => $sig) {
            $tok = (string)($chosenTokenIds[$pos] ?? '');
            $parts[] = '{' . $tok . ':' . $sig . '}';
        }
        return implode('', $parts);
    }

}
