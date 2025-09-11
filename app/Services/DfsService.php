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
     * @param array<string,int> $remainingSourceLetterCountsNeeded
     * @param array<int,int> $chosenSignatures map: pos => sig
     * @param array<int,string> $chosenTokens map: pos => token id
     */
    public function dfs(
        array $patternTokenPositions,
        array $remainingSourceLetterCountsNeeded,
        array $candidateSignaturesByToken,
        array $chosenSignatures,
        array $chosenTokens
    ): Generator
    {
        if (empty($patternTokenPositions)) {
            if (empty($remainingSourceLetterCountsNeeded)) {
                yield $this->buildSignatureIndexedPattern($chosenSignatures, $chosenTokens);
            }
            return;
        }

        $pos = key($patternTokenPositions);
        $token = $patternTokenPositions[$pos];
        unset($patternTokenPositions[$pos]);
        $candidates = $candidateSignaturesByToken[$token] ?? [];
        if (empty($candidates)) return; // dead end

        // Build viable indices by scanning candidates: must contain at least one needed letter and be able to fit
        $viableIndices = [];
        $all = (array)($candidates['signatures'] ?? []);
        foreach ($all as $i => $candidate) {
            $hist = (array)($candidate['hist'] ?? []);
            // Must share at least one needed letter
            $shares = false;
            foreach ($remainingSourceLetterCountsNeeded as $ch => $n) {
                if (isset($hist[$ch])) { $shares = true; break; }
            }
            if (!$shares) continue;
            // Must not exceed needed counts
            if ($this->candidateLettersExceedNeededCounts($remainingSourceLetterCountsNeeded, $hist)) continue;
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
            if ($this->candidateLettersExceedNeededCounts($remainingSourceLetterCountsNeeded, $hist)) continue;
            $nextNeed = $this->subtract($remainingSourceLetterCountsNeeded, $hist);
            // Additional pruning after choosing this candidate using slot-aware union (accounts for repeated tokens)
            $slotPrecomputed = [];
            foreach ($patternTokenPositions as $remPos => $remainingToken) {
                if (isset($candidateSignaturesByToken[$remainingToken])) {
                    $slotPrecomputed[] = $candidateSignaturesByToken[$remainingToken];
                }
            }
            if (!$this->unionCanFill($slotPrecomputed, $nextNeed)) continue;
            $nextChosenSignatures = $chosenSignatures; $nextChosenSignatures[$pos] = (string)$candidate['signature'];
            $nextChosenTokens = $chosenTokens; $nextChosenTokens[$pos] = $token;
            yield from $this->dfs($patternTokenPositions, $nextNeed, $candidateSignaturesByToken, $nextChosenSignatures, $nextChosenTokens);
        }
    }

    private function buildSignatureIndexedPattern(array $chosenSigs, array $chosenTokens): string
    {
        ksort($chosenSigs);
        ksort($chosenTokens);
        $parts = [];
        foreach ($chosenSigs as $pos => $sig) {
            $tok = (string)($chosenTokens[$pos] ?? '');
            $parts[] = '{' . $tok . ':' . $sig . '}';
        }
        return implode('', $parts);
    }

}
