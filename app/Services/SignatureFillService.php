<?php

namespace App\Services;

use App\Traits\HelpsMatchWords;
use Generator;

/**
 * SignatureFillService
 * --------------------
 * Purpose:
 *  Given a source signature, pattern token positions, and a candidate map
 *  of [token][word => signature], it fills the positions with candidates whose signatures collectively
 *  match the source letters. For each complete fill, it emits a compact "signatureIndexedPattern" string
 *  such as "{title:a}{forename:adn}{surname:aciinv}" in token position order.
 *
 */
final class SignatureFillService
{
    use HelpsMatchWords;

    /**
     * @param string $sourceSignature precomputed signature
     * @param array<int, string> $patternTokenPositions
     * @param array<string, array<string,string>> $matchingSignaturesByToken token => [word=>signature]
     * @return Generator<string>
     */
    public function generateSignaturePatterns(
        string $sourceSignature,
        array  $patternTokenPositions,
        array  $matchingSignaturesByToken
    ): Generator
    {
        $sourceLetterCountsNeeded = $this->letterCountsFromSignature($sourceSignature);
        $candidatesSignaturesByToken = $this->precomputeCandidateSignaturesByToken($matchingSignaturesByToken);

        // unionCanFill here causes false negatives on multi-token sigs
        $dfs = new DfsService();
        yield from $dfs->dfs($patternTokenPositions, $sourceLetterCountsNeeded, $candidatesSignaturesByToken, [], []);
    }

    /** Build per-token candidates: signatures with precomputed histograms, per-letter maxima, and a letter index */
    private function precomputeCandidateSignaturesByToken(array $matchingSignaturesByToken): array
    {
        $candidateSignaturesByToken = [];
        foreach ($matchingSignaturesByToken as $token => $signaturesByWord) {
            // Step 1: build candidate list with per-candidate histograms
            $candidates = [];
            foreach ($signaturesByWord as $signature) {
                $candidates[] = [
                    'signature' => $signature,
                    'len' => strlen($signature),
                    'hist' => $this->letterCountsFromSignature($signature),
                ];
            }
            // Sort deterministically (shorter first, then signature)
            usort($candidates, function($a, $b){
                if ($a['len'] === $b['len']) {
                    return $a['signature'] <=> $b['signature'];
                }
                return $a['len'] < $b['len'] ? -1 : 1;
            });
            // Step 2: rebuild letter indices and per-letter maxima on sorted candidates
            $letterIndices = [];
            $maxLetterCounts = [];
            foreach ($candidates as $i => $candidate) {
                foreach ($candidate['hist'] as $ch => $n) {
                    $maxLetterCounts[$ch] = max($maxLetterCounts[$ch] ?? 0, (int)$n);
                    $letterIndices[$ch] = $letterIndices[$ch] ?? [];
                    $letterIndices[$ch][] = $i;
                }
            }
            $candidateSignaturesByToken[$token] = [
                'signatures' => $candidates,
                'maxLetterCounts' => $maxLetterCounts,
                'letterIndices' => $letterIndices,
            ];
        }
        return $candidateSignaturesByToken;
    }

}
