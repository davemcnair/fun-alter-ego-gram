<?php

namespace App\Services;

use App\Dtos\PhraseDto;

final class PhraseBuilderService
{
    /**
     * Format a phrase given the chosen words and their slot definitions.
     * When $displayDoubleSurnameVariants is true, and a consecutive surname run has length 2,
     * the surname token will list both hyphen variants comma-separated (e.g., "Dim-Vinci, Vinci-Dim").
     * For any other run length, the canonical single hyphen chain is produced.
     *
     * @param array<int,array> $slots      List-typed words in the original slot order (one per slot) eg fun:wibble
     */
    public function formatPhraseBySlots(array $slots): PhraseDto
    {
        $parts = [];
        $wi = 0; // index into $slotWords
        $n = count($slots);
        for ($i = 0; $i < $n; $i++) {
            $slot = $slots[$i];
            $name = strtolower((string)($slot['name'] ?? ''));

            if (in_array($name, ['forename','surname'])) {
                $variants = [];
                // Collect this and any subsequent consecutive slots of the same token type
                $j = $i;
                while ($j < $n && strtolower((string)($slots[$j]['name'] ?? '')) === $name) {
                    $word = $slots[$wi]['word'];
                    // Capitalize: first letter uppercase, rest lowercase with in-word title casing
                    $word = $this->capitalizeWord($word);
                    $variants[] = $word;
                    $wi++; $j++;
                }
                // Move outer loop to the last consumed slot
                $i = $j - 1;
                if (!empty($variants)) {
                    $parts[] = implode('-', $variants);
                }
            } else {
                $word = $slots[$wi]['word'];
                $parts[] = $word;
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
