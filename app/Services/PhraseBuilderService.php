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
     * @param array<int,string> $slotWords      Words in the original slot order (one per slot)
     * @param array<int,array{name:string,pos:int}> $slotOrder Slot definitions in the original order
     * @param bool $displayMultipleVariants If true, lists both variants for double-surname runs (display-only)
     */
    public function formatPhraseBySlots(array $slotWords, array $slotOrder, bool $displayMultipleVariants = false): string
    {
        $parts = [];
        $wi = 0; // index into $slotWords
        $n = count($slotOrder);
        for ($i = 0; $i < $n; $i++) {
            $slot = $slotOrder[$i];
            $name = strtolower((string)($slot['name'] ?? ''));

            if (in_array($name, ['forename','surname'])) {
                $variants = [];
                // Collect this and any subsequent consecutive slots of the same token type
                $j = $i;
                while ($j < $n && strtolower((string)($slotOrder[$j]['name'] ?? '')) === $name) {
                    $word = $this->firstOf($slotWords[$wi] ?? '');
                    // Capitalize: first letter uppercase, rest lowercase with in-word title casing
                    $word = $this->capitalizeWord($word);
                    if ($word !== '') $variants[] = $word;
                    $wi++; $j++;
                }
                // Move outer loop to the last consumed slot
                $i = $j - 1;
                if (!empty($variants)) {
                    if ($displayMultipleVariants && count($variants) === 2) {
                        // For display, list both hyphen variants for exactly two surnames
                        $ab = $variants[0] . '-' . $variants[1];
                        $ba = $variants[1] . '-' . $variants[0];
                        $parts[] = $ab . ', ' . $ba;
                    } else {
                        $parts[] = implode('-', $variants);
                    }
                }
            } else {
                $word = $this->firstOf($slotWords[$wi] ?? '');
                if ($word !== '') $parts[] = $word;
                $wi++;
            }
        }
        return trim(implode(' ', $parts));
    }

    /**
     * Normalize a slot value (string|array|Traversable) to its first string element.
     */
    private function firstOf(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (is_array($value)) return (string) (array_values($value)[0] ?? '');
        if ($value instanceof \Traversable) {
            foreach ($value as $v) { return (string)$v; }
            return '';
        }
        // Collections from Laravel also implement Traversable, but keep a safe fallback
        if (is_object($value) && method_exists($value, 'first')) {
            $first = $value->first();
            return $first !== null ? (string)$first : '';
        }
        return '';
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
