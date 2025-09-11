<?php

namespace App\Services;

use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use Generator;

/**
 * SignatureFillService
 * --------------------
 * Purpose:
 *  Given a source signature, pattern token positions, and a list of matchingTokenSignatureWord ids,
 *  it fills the positions with candidates whose signatures collectively match the source letters.
 *  For each complete fill, it emits a compact "signatureIndexedPattern" string
 *  such as "{1:a}{2:adn}{5:aciinv}" in token position order.
 *
 */
final class SignatureFillService
{
    use HelpsMatchWords;

    /**
     * @param string $sourceSignature
     * @param array $patternTokenPositions
     * @param array $matchingTokenSignatureWordIds
     * @return Generator
     */
    public function generateSignaturePatterns(
        string $sourceSignature,
        array  $patternTokenPositions,
        array  $matchingTokenSignatureWordIds
    ): Generator
    {
        $sourceLetterCountsNeeded = $this->letterCountsFromSignature($sourceSignature);
        $candidatesSignaturesByTokenId = $this->precomputeCandidateSignaturesByTokenId($matchingTokenSignatureWordIds);

        $dfs = new DfsService();
        yield from $dfs->dfs($patternTokenPositions, $sourceLetterCountsNeeded, $candidatesSignaturesByTokenId, [], []);
    }

    /** Build per-token_id candidates: signatures with precomputed histograms, per-letter maxima, and a letter index */
    private function precomputeCandidateSignaturesByTokenId(array $matchingTokenSignatureWordIds): array
    {
        $candidateSignaturesByTokenId = [];
        foreach ($matchingTokenSignatureWordIds as $id) {
            $model = TokenSignatureWord::find($id);
            $signature = $model->tokenSignature->signature;
            $token_id = $model->tokenSignature->token_id;
            // Step 1: build candidate list with per-candidate histograms
            $candidateSignaturesByTokenId[$token_id]['signatures'][] = [
                'signature' => $signature,
                'len' => strlen($signature),
                'hist' => $this->letterCountsFromSignature($signature),
            ];
        }
        foreach ($candidateSignaturesByTokenId as $token_id => &$bucket) {
            $list = (array)($bucket['signatures'] ?? []);
            // Sort deterministically (shorter first, then signature)
            usort($list, function($a, $b){
                if (($a['len'] ?? 0) === ($b['len'] ?? 0)) {
                    return ($a['signature'] ?? '') <=> ($b['signature'] ?? '');
                }
                return ($a['len'] ?? 0) < ($b['len'] ?? 0) ? -1 : 1;
            });
            // Step 2: rebuild letter indices and per-letter maxima on sorted candidates
            $letterIndices = [];
            $maxLetterCounts = [];
            foreach ($list as $i => $candidate) {
                $hist = (array)($candidate['hist'] ?? []);
                foreach ($hist as $ch => $n) {
                    $maxLetterCounts[$ch] = max($maxLetterCounts[$ch] ?? 0, (int)$n);
                    $letterIndices[$ch] = $letterIndices[$ch] ?? [];
                    $letterIndices[$ch][] = $i;
                }
            }
            $candidateSignaturesByTokenId[$token_id] = [
                'signatures' => $list,
                'maxLetterCounts' => $maxLetterCounts,
                'letterIndices' => $letterIndices,
            ];
        }
        return $candidateSignaturesByTokenId;
    }

}
