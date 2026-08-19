<?php

namespace App\Support;

use App\Dtos\SignatureDto;

/**
 * Letter identity for Target names and Token words, plus Target-only display/dedup keys.
 */
class NameNormalizer
{
    /**
     * Order-preserving canonical key for Target deduplication.
     */
    public static function canonicalKey(string $input): string
    {
        $s = trim($input);
        if ($s === '') {
            return '';
        }

        $ascii = self::asciiFold($s);
        $ascii = str_replace(["'", "’", "`", "´"], '', $ascii);
        $ascii = preg_replace('/[^a-z0-9]+/i', ' ', $ascii) ?? '';
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
        if ($ascii !== '' && ! str_contains($ascii, ' ')) {
            if (preg_match('/^(.*?)(mc[a-z]+)$/', $ascii, $m)) {
                $ascii = trim($m[1].' '.$m[2]);
            }
        }
        return $ascii;
    }

    /**
     * Letters only, original relative order, after transliteration.
     * Used when storing a Token word.
     */
    public static function letterString(string $input): string
    {
        return preg_replace('/[^a-z]/', '', self::asciiFold($input)) ?? '';
    }

    /**
     * Sorted letter multiset plus per-letter counts (a Signature).
     */
    public static function anagramSignature(string $input): SignatureDto
    {
        $norm = self::letterString($input);
        $chars = $norm === '' ? [] : str_split($norm);
        sort($chars);
        $signature = implode('', $chars);
        $letterCounts = [];
        $len = strlen($signature);
        for ($i = 0; $i < $len; $i++) {
            $ch = $signature[$i];
            $letterCounts[$ch] = ($letterCounts[$ch] ?? 0) + 1;
        }
        $defaults = ['length' => $len];
        foreach (range('a', 'z') as $ch) {
            $defaults[$ch.'_count'] = (int) ($letterCounts[$ch] ?? 0);
        }

        return new SignatureDto($signature, $defaults);
    }

    /**
     * Human-friendly Target display: collapse whitespace, title-case, keep punctuation.
     */
    public static function displayName(string $input): string
    {
        $s = trim($input);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        $s = preg_replace_callback('/([\'\'’])(\p{L})/u', function (array $m) {
            return $m[1].mb_strtoupper($m[2], 'UTF-8');
        }, $s) ?? $s;
        return $s;
    }

    private static function asciiFold(string $s): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii === false) {
            $ascii = $s;
        }
        return strtolower($ascii);
    }
}
