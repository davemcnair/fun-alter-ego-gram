<?php

namespace App\Support;

/**
 * Utility for normalizing human-entered names.
 * - canonicalKey: ASCII, lowercase, keep only [a-z0-9]
 * - displayName: collapse whitespace and title-case words
 */
class NameNormalizer
{
    /**
     * Build a canonical key for deduplication.
     * Steps: trim, transliterate to ASCII, lowercase, keep only [a-z0-9].
     */
    public static function canonicalKey(string $input): string
    {
        $s = trim($input);
        if ($s === '') return '';

        // Best-effort transliteration to ASCII
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii === false) {
            $ascii = $s; // fallback: use original
        }
        $ascii = strtolower($ascii);
        // Keep only letters and digits
        $ascii = preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
        return $ascii;
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
