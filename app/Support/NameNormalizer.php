<?php

namespace App\Support;

use App\Dtos\SignatureDto;

/**
 * Utility for normalizing human-entered names.
 * - canonicalKey: lowercase, accent-folded, spaces preserved, punctuation removed, single-space collapsed
 * - anagramSignature: lowercase, accent-folded, keep [a-z0-9], sort characters
 * - displayName: collapse whitespace and title-case words (preserve punctuation)
 */
class NameNormalizer
{
    /**
     * Build an order-preserving canonical key for deduplication.
     * Steps:
     *  - trim, lowercase
     *  - unicode-fold (transliterate accents)
     *  - remove all punctuation except spaces
     *  - collapse internal whitespace to single spaces
     *  - preserve word order
     */
    public static function canonicalKey(string $input): string
    {
        $s = trim($input);
        if ($s === '') return '';

        // Best-effort transliteration to ASCII
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii === false) {
            $ascii = $s; // fallback
        }
        $ascii = strtolower($ascii);
        // Remove apostrophe-like diacritics that may appear after transliteration (avoid splitting words)
        $ascii = str_replace(["'", "’", "`", "´"], '', $ascii);
        // Replace any remaining non-letter/digit with space, but preserve spaces effectively
        $ascii = preg_replace('/[^a-z0-9]+/i', ' ', $ascii) ?? '';
        // Collapse spaces
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
        // Heuristic: if there are no spaces and we find an internal 'mc' surname, split before it
        if ($ascii !== '' && !str_contains($ascii, ' ')) {
            if (preg_match('/^(.*?)(mc[a-z]+)$/', $ascii, $m)) {
                $ascii = trim($m[1] . ' ' . $m[2]);
            }
        }
        return $ascii;
    }

    /**
     * Build a bag-of-letters anagram signature (non-unique, for discovery).
     * Steps:
     *  - trim, lowercase
     *  - unicode-fold
     *  - keep only [a-z0-9]
     *  - sort characters
     */
    public static function anagramSignature(string $input): SignatureDto
    {
        $s = trim($input);
        if ($s === '') return '';
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii === false) {
            $ascii = $s;
        }
        return SignatureDto::fromWord($ascii);
    }

    /**
     * Produce a human-friendly display string.
     * Steps: trim, collapse whitespace to single spaces, title-case words.
     * Preserve punctuation and spaces in output.
     */
    public static function displayName(string $input): string
    {
        $s = trim($input);
        if ($s === '') return '';
        // Collapse internal whitespace to single spaces
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        // Title-case according to locale using multibyte convert
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        // Preserve capitalization after apostrophes like O’Connor or O'Neil by uppercasing the letter after the apostrophe
        $s = preg_replace_callback('/([\'\'’])(\p{L})/u', function(array $m) {
            return $m[1] . mb_strtoupper($m[2], 'UTF-8');
        }, $s) ?? $s;
        return $s;
    }
}
