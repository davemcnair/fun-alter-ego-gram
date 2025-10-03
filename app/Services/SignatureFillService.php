<?php

namespace App\Services;

use App\Models\TargetTokenSignature;
use App\Traits\HelpsMatchWords;
use Generator;
use Illuminate\Support\Collection;

/**
 * SignatureFillService
 * --------------------
 * Purpose:
 *  Given a source signature, pattern token positions, and a list of matchingTokenSignatureWord ids,
 *  it fills the positions with candidates whose signatures collectively match the source letters.
 *  For each complete fill, it emits a compact "targetSignatureIndexedPattern" string
 *  such as "{1:a}{2:adn}{5:aciinv}" in token position order.
 *
 */
class SignatureFillService
{
    use HelpsMatchWords;

    /**
     * @param string $targetSignature
     * @param array $patternTokenPositions
     * @param Collection<TargetTokenSignature> $matchingTokenSignatures
     * @return Generator
     */
    public function generateSignaturePatterns(
        array $targetLetterCountsNeeded,
        array  $patternTokenPositions,
        Collection $matchingTokenSignatures
    ): Generator
    {
        $tokenSignaturesByTokenId = $this->buildGroupedTargetTokenSignaturesByTokenId($matchingTokenSignatures);

        $dfs = new DfsService();
        yield from $dfs->dfs($patternTokenPositions, $targetLetterCountsNeeded, $tokenSignaturesByTokenId, []);
    }

    /**
     * Build targetTokenSignature groups per token_id for DFS.
     *
     * Returns an array keyed by token_id with:
     * - 'targetTokenSignatures': list
     * - 'maxLetterCounts': per-letter maxima across candidates
     * - 'letterIndices': map letter => sorted list of candidate indices containing that letter
     *
     * @param Collection<TargetTokenSignature> $targetTokenSignatures
     * @return array<int,array{
     *   tokenSignatures: array<int,TargetTokenSignature>,
     *   maxLetterCounts: array<string,int>,
     *   letterIndices: array<string,array<int,int>>
     * }>
     */
    private function buildGroupedTargetTokenSignaturesByTokenId(Collection $targetTokenSignatures): array
    {
        // todo build using token_signature_id
        $grouped = [];
        foreach ($targetTokenSignatures as $targetTokenSignature) {
            $tokenSignature = $targetTokenSignature->tokenSignature;
            $grouped[$tokenSignature->token_id][] = $targetTokenSignature;
        }

        $result = [];
        foreach ($grouped as $token_id => $targetTokenSignaturesGroup) {
            // Deterministic sort: first by length, then signature string
            usort($targetTokenSignaturesGroup, function(TargetTokenSignature $a, TargetTokenSignature $b) {
                if ($a->tokenSignature->signature->length === $b->tokenSignature->signature->length) {
                    return $a->tokenSignature->signature->signature <=> $b->tokenSignature->signature->signature;
                }
                return $a->tokenSignature->signature->length <=> $b->tokenSignature->signature->length;
            });

            // Build indices and per-letter maxima from sorted list
            // Precompute letterCounts for each signature to avoid repeated method calls in DFS
            $letterIndices = [];
            $maxLetterCounts = [];
            $precomputedLetterCounts = [];
            foreach ($targetTokenSignaturesGroup as $i => $targetTokenSignature) {
                $letterCounts = $targetTokenSignature->tokenSignature->signature->letterCounts();
                $precomputedLetterCounts[$i] = $letterCounts;
                foreach ($letterCounts as $ch => $n) {
                    $maxLetterCounts[$ch] = max($maxLetterCounts[$ch] ?? 0, $n);
                    $letterIndices[$ch] = $letterIndices[$ch] ?? [];
                    $letterIndices[$ch][] = $i;
                }
            }

            $result[$token_id] = [
                'targetTokenSignatures' => $targetTokenSignaturesGroup,
                'maxLetterCounts' => $maxLetterCounts,
                'letterIndices' => $letterIndices,
                'precomputedLetterCounts' => $precomputedLetterCounts,
            ];
        }

        return $result;
    }
}
