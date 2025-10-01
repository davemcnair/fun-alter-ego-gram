<?php
namespace App\Traits;

trait HelpsMatchWords
{
    // ASCII-only normalization: keep a–z, lowercase; drop diacritics and any non-ASCII letters (no transliteration)
    public function normalize(string $s): string
    {
        $s = strtolower($s);
        // Remove any character outside ASCII a-z. Accented letters are dropped.
        return preg_replace('/[^a-z]/', '', $s) ?? '';
    }

    // Make a sorted-letter signature string from input string
    public function makeSignature(string $s): string
    {
        $norm = $this->normalize($s);
        $chars = str_split($norm);
        sort($chars);
        return implode('', $chars);
    }

    // Check if sorted multiset $small is a subset of sorted multiset $big (ASCII)
    public function isSubset(string $small, string $big): bool
    {
        $i = 0; $j = 0; $ls = strlen($small); $lb = strlen($big);
        if ($ls === 0) {
            return true;
        }
        while ($i < $ls && $j < $lb) {
            $cs = $small[$i];
            $cb = $big[$j];
            if ($cs === $cb) { $i++; $j++; }
            elseif ($cs > $cb) { $j++; }
            else { return false; }
        }
        return $i === $ls;
    }

    public function candidateLettersExceedNeededCounts(array $neededLetterCounts, array $candidateLetterCounts): bool
    {
        foreach ($candidateLetterCounts as $ch => $n) {
            if (($neededLetterCounts[$ch] ?? 0) < $n) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,int> $a @param array<string,int> $b */
    public function subtract(array $a, array $b): array
    {
        foreach ($b as $ch => $n) {
            if (!isset($a[$ch])) {
                continue;
            } // should not happen after canCover
            $a[$ch] -= $n;
            if ($a[$ch] <= 0) {
                unset($a[$ch]);
            }
        }
        return $a;
    }

    /**
     * Quick upper-bound feasibility for remaining slots
     *
     * Slot-aware upper bound union: sum per-letter maxima per remaining slot (handles repeated tokens)
     */
    public function unionCanFill(array $tokenPrecomputed, array $signatureLetterCounts): bool
    {
        $supply = [];
        foreach ($tokenPrecomputed as $precomputed) {
            $maxes = $precomputed['maxLetterCounts'];
            foreach ($signatureLetterCounts as $ch => $n) {
                if (!isset($maxes[$ch])) {
                    continue;
                }
                $supply[$ch] = ($supply[$ch] ?? 0) + (int)$maxes[$ch];
            }
        }
        foreach ($signatureLetterCounts as $ch => $n) {
            if (($supply[$ch] ?? 0) < $n) {
                return false;
            }
        }
        return true;
    }
}
