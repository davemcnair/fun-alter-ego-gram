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
     * @param bool $displayDoubleSurnameVariants If true, lists both variants for double-surname runs (display-only)
     */
    public function formatPhraseBySlots(array $words, array $slotOrder, bool $displayDoubleSurnameVariants = false): string
    {
        $parts = [];
        $wi = 0; // index into $words
        $n = count($slotOrder);
        for ($i = 0; $i < $n; $i++) {
            $slot = $slotOrder[$i];
            $name = strtolower((string)($slot['name'] ?? ''));

            if ($name === 'surname') {
                $surnames = [];
                // Collect this and any subsequent consecutive surname slots
                $j = $i;
                while ($j < $n && strtolower((string)($slotOrder[$j]['name'] ?? '')) === 'surname') {
                    $word = $words[$wi] ?? '';
                    // Capitalize: first letter uppercase, rest lowercase
                    $word = $this->capitalizeWord($word);
                    if ($word !== '') $surnames[] = $word;
                    $wi++; $j++;
                }
                // Move outer loop to the last consumed surname slot
                $i = $j - 1;
                if (!empty($surnames)) {
                    if ($displayDoubleSurnameVariants && count($surnames) === 2) {
                        // If the surname block is at the end of the phrase, duplicate the full phrase with both orders
                        $ab = $surnames[0] . '-' . $surnames[1];
                        $ba = $surnames[1] . '-' . $surnames[0];
                        // List both orders within the surname token (keeps entire phrase a single string)
                        $parts[] = $ab . ', ' . $ba;
                    } else {
                        $parts[] = implode('-', $surnames);
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
        // Basic ASCII title-case for consistency; input words expected to be [A-Za-z]+
        $lw = strtolower($w);
        return strtoupper($lw[0]) . substr($lw, 1);
    }
}
