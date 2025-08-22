<?php

namespace App\Services;

class TextSignatureService
{
    // ASCII-only normalization: keep a–z and digits, lowercase
    public function normalize(string $s): string
    {
        $s = strtolower($s);
        return preg_replace('/[^a-z0-9]/i', '', $s) ?? '';
    }

    // Make a sorted-letter signature from input string
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
        if ($ls === 0) return true;
        while ($i < $ls && $j < $lb) {
            $cs = $small[$i];
            $cb = $big[$j];
            if ($cs === $cb) { $i++; $j++; }
            elseif ($cs > $cb) { $j++; }
            else { return false; }
        }
        return $i === $ls;
    }
}
