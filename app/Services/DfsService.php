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
     * @param array<int,string> $chosenSignatures map: pos => sig
     * @param array<int,string> $chosenTokens map: pos => token name
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
                yield $this->buildSignaturedPattern($chosenSignatures, $chosenTokens);
            }
            return;
        }

        $pos = key($patternTokenPositions);
        $token = $patternTokenPositions[$pos];
        unset($patternTokenPositions[$pos]);
        $candidates = $candidateSignaturesByToken[$token] ?? [];
        if (empty($candidates)) return; // dead end

        foreach ($candidates['signatures'] as $candidate) {
            $signature = $candidate['signature'];
            $letterCounts = $this->letterCountsFromSignature($signature);
            if ($this->candidateLettersExceedNeededCounts($remainingSourceLetterCountsNeeded, $letterCounts)) continue;
            $nextNeed = $this->subtract($remainingSourceLetterCountsNeeded, $letterCounts);
            // could remainingCandidateSignaturesByToken be calculated here for multi-slot tokens?
            // Additional pruning after choosing this candidate
            if (!$this->unionCanFill($candidateSignaturesByToken, $nextNeed)) continue;
            $nextChosenSigs = $chosenSignatures;
            $nextChosenSigs[$pos] = $signature;
            $nextChosenTokens = $chosenTokens;
            $nextChosenTokens[$pos] = $token;
            yield from $this->dfs($patternTokenPositions, $nextNeed, $candidateSignaturesByToken, $nextChosenSigs, $nextChosenTokens);
        }
    }

    private function buildSignaturedPattern(array $chosenSigs, array $chosenTokens): string
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
