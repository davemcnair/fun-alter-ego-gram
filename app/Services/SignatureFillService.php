<?php

namespace App\Services;

use App\Traits\HelpsMatchWords;
use Generator;

/**
 * SignatureFillService
 * --------------------
 * Purpose:
 *  Like Anagrammer, but operates purely on signatures and does not build phrases.
 *  Given a source (or its signature), a pattern (as ordered token slots), and a candidate map
 *  of [token][word => signature], it fills the slots with candidates whose signatures collectively
 *  match the source letters. For each complete fill, it emits a compact "signaturedPattern" string
 *  such as "{title:a}{forename:adn}{surname:aciinv}" in slot order.
 *
 * Input shape:
 *  - $candidatesByToken: array<string, array<string,string>>  // token => [word => signature]
 *  - $patternTokenPositions: array<int, array{name:string,pos:int}>           // ordered slots, like Anagrammer
 *
 * Output:
 *  - generateSignaturePatterns($sourceNameOrSignature, $patternTokenPositions, $candidatesByToken): \Generator<string>
 *    yields signaturedPattern strings; no phrases are generated.
 *
 * How precomputing maxLetterCountsPerToken prunes the search:
 *  - During precompute we build, for each token, a map of letter -> maximum count across its candidates
 *    (e.g., for token "surname": { c:2, i:2, n:1, v:1, a:1, r:1, y:1 }).
 *  - Given remaining pattern slots and the remaining letter need, unionCanFill sums these maxima per slot to
 *    form an optimistic upper-bound supply histogram. If any needed letter exceeds this optimistic supply,
 *    we can prove no completion exists for this branch and prune it immediately.
 *  - This is safe because the supply is an upper bound (it assumes we could pick candidates that maximize each
 *    letter simultaneously). If even the upper bound can’t meet the need, the real set certainly can’t either.
 *  - This reduces branching dramatically for larger patterns or when candidate sets are broad, since the check
 *    is O(S + L) for S slots and L letters still needed, vs. exploring many candidate combinations.
 */
final class SignatureFillService
{
    use HelpsMatchWords;

    /** @var array<string, array<int, array{sig:string,len:int,name:string}>> */
    private array $candidatesByToken = [];

    /** @var array<string, array<string, int>> per-token: letter => max count across its candidates */
    private array $maxLetterCountsByToken = [];

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

        // Quick upper-bound check: if union of per-slot maxima can’t cover required letters, bail
        if (!$this->unionCanFill($candidatesSignaturesByToken, $sourceLetterCountsNeeded)) {
            return;
        }
        $dfs = new DfsService();
        yield from $dfs->dfs($patternTokenPositions, $sourceLetterCountsNeeded, $candidatesSignaturesByToken, [], []);
    }



    /** Build an array of per-token candidates with signatures, signature lengths and max counts per-letter */
    private function precomputeCandidateSignaturesByToken(array $matchingSignaturesByToken): array
    {
        $candidatesSignaturesByToken = [];
        foreach ($matchingSignaturesByToken as $token => $signatures) {
            $maxLetterCountsForToken = [];
            foreach ($signatures as $signature) {
                $signatureLetterCounts = $this->letterCountsFromSignature($signature);
                foreach ($signatureLetterCounts as $ch => $n) {
                    $prev = $maxLetterCountsForToken[$ch] ?? 0;
                    if ($n > $prev) $maxLetterCountsForToken[$ch] = $n;
                }
                $candidatesSignaturesByToken[$token]['signatures'][] = [
                    'signature' => $signature,
                    'len' => strlen($signature),
                ];
            }
            $candidatesSignaturesByToken[$token]['maxLetterCounts'] = $maxLetterCountsForToken;
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



    /** @param array<string,int> $neededLetterCounts @param array<string,int> $cand */
    private function candidateLettersExceedNeededCounts(array $neededLetterCounts, array $cand): bool
    {
        foreach ($cand as $ch => $n) {
            if (($neededLetterCounts[$ch] ?? 0) < $n) return false;
        }
        return true;
    }

    /** @param array<string,int> $a @param array<string,int> $b */
    private function subtract(array $a, array $b): array
    {
        foreach ($b as $ch => $n) {
            if (!isset($a[$ch])) continue;
            $a[$ch] -= $n;
            if ($a[$ch] <= 0) unset($a[$ch]);
        }
        return $a;
    }
}
