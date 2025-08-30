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
     * @param array<int,string> $words      Words in the original slot order (one per slot)
     * @param array<int,array{name:string,pos:int}> $slotOrder Slot definitions in the original order
     */
    public function formatPhraseBySlots(array $words, array $slotOrder): string
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
                    $parts[] = implode('-', $surnames);
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
