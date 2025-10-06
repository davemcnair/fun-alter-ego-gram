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
 *
 */
class SignatureFillService
{
    use HelpsMatchWords;

    /**
     * @param string $targetSignature
     * @param array $patternTokenPositions
     * @param Collection<TargetTokenSignature> $targetTokenSignatures
     * @return Generator
     */
    public function generateSignaturedPatterns(
        array $targetLetterCountsNeeded,
        array  $patternTokenPositions,
        Collection $targetTokenSignatures
    ): Generator
    {
        $tokenSignaturesByTokenId = $this->buildGroupedTargetTokenSignaturesByTokenId($targetTokenSignatures);

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
            $maxLetterCounts = [];
            $precomputedLetterCounts = [];
            foreach ($targetTokenSignaturesGroup as $i => $targetTokenSignature) {
                $letterCounts = $targetTokenSignature->tokenSignature->signature->letterCounts();
                $precomputedLetterCounts[$i] = $letterCounts;
                foreach ($letterCounts as $ch => $n) {
                    $maxLetterCounts[$ch] = max($maxLetterCounts[$ch] ?? 0, $n);
                }
            }

            $result[$token_id] = [
                'targetTokenSignatures' => $targetTokenSignaturesGroup,
                'maxLetterCounts' => $maxLetterCounts,
                'precomputedLetterCounts' => $precomputedLetterCounts,
            ];
        }

        return $result;
    }
}
