<?php

namespace App\Services;

final class PhraseBuilderService
{
    /**
     * Format a phrase given the chosen words and their slot definitions.
     * - Joins tokens with spaces.
     * - If one or more consecutive slots are 'surname', capitalize each surname word
     *   and join them with hyphens.
     * - Non-surname tokens are included as-is.
     *
     * When $displayDoubleSurnameVariants is true, and a consecutive surname run has length 2,
     * the surname token will list both hyphen variants comma-separated (e.g., "Dim-Vinci, Vinci-Dim").
     * For any other run length, the canonical single hyphen chain is produced.
     *
     * @param array<int,string> $words      Words in the original slot order (one per slot)
     * @param array<int,array{name:string,pos:int}> $slotOrder Slot definitions in the original order
     * @param bool $displayMultipleVariants If true, lists both variants for double-surname runs (display-only)
     */
    public function formatPhraseBySlots(array $words, array $slotOrder, bool $displayMultipleVariants = false): string
    {
        $parts = [];
        $wi = 0; // index into $words
        $n = count($slotOrder);
        for ($i = 0; $i < $n; $i++) {
            $slot = $slotOrder[$i];
            $name = strtolower((string)($slot['name'] ?? ''));

            if (in_array($name, ['forename','surname'])) {
                $variants = [];
                // Collect this and any subsequent consecutive multi slots
                $j = $i;
                while ($j < $n && strtolower((string)($slotOrder[$j]['name'] ?? '')) === $name) {
                    $word = $words[$wi] ?? '';
                    // Capitalize: first letter uppercase, rest lowercase
                    $word = $this->capitalizeWord($word);
                    if ($word !== '') $variants[] = $word;
                    $wi++; $j++;
                }
                // Move outer loop to the last consumed surname slot
                $i = $j - 1;
                if (!empty($variants)) {
                    if ($displayMultipleVariants && count($variants) === 2) {
                        // If the surname block is at the end of the phrase, list both orders for display
                        $ab = $variants[0] . '-' . $variants[1];
                        $ba = $variants[1] . '-' . $variants[0];
                        $parts[] = $ab . ', ' . $ba;
                    } else {
                        $parts[] = implode('-', $variants);
                    }
                }
            } else {
                $word = $words[$wi] ?? '';
                if ($word !== '') $parts[] = $word;
                $wi++;
            }
        }
        return trim(implode(' ', $parts));
    }

    private function capitalizeWord(string $w): string
    {
        if ($w === '') return $w;
        // Title-case within the word: capitalize first letter and any letter after apostrophe or hyphen
        $lw = strtolower($w);
        $len = strlen($lw);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $lw[$i];
            if ($i === 0) {
                $out .= strtoupper($ch);
            } else {
                $prev = $lw[$i-1];
                if ($prev === "'" || $prev === '-') {
                    $out .= strtoupper($ch);
                } else {
                    $out .= $ch;
                }
            }
        }
        return $out;
    }
}
