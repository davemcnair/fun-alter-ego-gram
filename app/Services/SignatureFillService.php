<?php

namespace App\Services;

use App\Models\TargetTokenSignature;
use App\Models\TokenSignature;
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
        $tokenSignaturesByTokenId = $this->buildPrecomputedCandidatesByTokenId($matchingTokenSignatures);

        $dfs = new DfsService();
        yield from $dfs->dfs($patternTokenPositions, $targetLetterCountsNeeded, $tokenSignaturesByTokenId, [], []);
    }

    /**
     * Build precomputed candidate buckets per token_id for DFS.
     *
     * Returns an array keyed by token_id with:
     * - 'signatures': list of candidates [signature, len, hist, signature_id]
     * - 'maxLetterCounts': per-letter maxima across candidates
     * - 'letterIndices': map letter => sorted list of candidate indices containing that letter
     *
     * @param Collection<TargetTokenSignature> $targetTokenSignatures
     * @return array<int,array{
     *   signatures: array<int,array{signature:string,len:int,hist:array<string,int>,signature_id:int|null}>,
     *   maxLetterCounts: array<string,int>,
     *   letterIndices: array<string,array<int,int>>
     * }>
     */
    private function buildPrecomputedCandidatesByTokenId(Collection $targetTokenSignatures): array
    {
        // todo build using token_signature_id
        $grouped = [];
        foreach ($targetTokenSignatures as $targetTokenSignature) {
            $tokenSignature = $targetTokenSignature->tokenSignature;
            $grouped[$tokenSignature->token_id][] = $tokenSignature;
        }

        $result = [];
        foreach ($grouped as $token_id => $tokenSignatures) {
            // Deterministic sort: first by length, then signature string
            usort($tokenSignatures, function(TokenSignature $a, TokenSignature $b) {
                if ($a->signature->length === $b->signature->length) {
                    return $a->signature->signature <=> $b->signature->signature;
                }
                return $a->signature->length <=> $b->signature->length;
            });

            // Build indices and per-letter maxima from sorted list
            $letterIndices = [];
            $maxLetterCounts = [];
            foreach ($tokenSignatures as $i => $tokenSignature) {
                foreach ($tokenSignature->letterCounts() as $ch => $n) {
                    $maxLetterCounts[$ch] = max($maxLetterCounts[$ch] ?? 0, $n);
                    $letterIndices[$ch] = $letterIndices[$ch] ?? [];
                    $letterIndices[$ch][] = $i;
                }
            }

            $result[$token_id] = [
                'tokenSignatures' => $tokenSignatures,
                'maxLetterCounts' => $maxLetterCounts,
                'letterIndices' => $letterIndices,
            ];
        }

        return $result;
    }
}
