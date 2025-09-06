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
 *  match the source letters. For each complete fill, it emits a compact "signaturedPattern" string
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
        $candidatesSignaturesByToken = [];
        foreach ($matchingSignaturesByToken as $token => $signatures) {
            $maxLetterCountsForToken = [];
            $letterIndicesForToken = [];
            foreach ($signatures as $i => $signature) {
                $signatureLetterCounts = $this->letterCountsFromSignature($signature);
                foreach ($signatureLetterCounts as $ch => $n) {
                    $prev = $maxLetterCountsForToken[$ch] ?? 0;
                    if ($n > $prev) $maxLetterCountsForToken[$ch] = $n;
                    // build letter index: letter => [candidate indices]
                    // todo: before or after usort?
                    $letterIndex[$ch] = $letterIndex[$ch] ?? [];
                    $letterIndex[$ch][] = $i;
                }
                $candidatesSignaturesByToken[$token]['signatures'][] = [
                    'signature' => $signature,
                    'len' => strlen($signature),
                ];
            }
            $candidatesSignaturesByToken[$token]['maxLetterCounts'] = $maxLetterCountsForToken;
            $candidatesSignaturesByToken[$token]['letterIndices'] = $letterIndicesForToken;
            // sort for deterministic ordering: shorter signatures first, then signature alphabetically
            usort($candidatesSignaturesByToken[$token]['signatures'], function($a, $b){
                if ($a['len'] === $b['len']){
                    return $a['signature'] <=> $b['signature'];
                }
                return $a['len'] <=> $b['len'];
            });

        }
        return $candidatesSignaturesByToken;
    }

}
