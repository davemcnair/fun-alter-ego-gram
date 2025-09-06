<?php

namespace Tests\Unit;

use App\Services\Anagrammer;
use PHPUnit\Framework\TestCase;

class AnagrammerValidationTest extends TestCase
{
    /**
     * The anagrammer must never emit phrases whose letters don't exactly match the source letters.
     * Here, "victoria chandler" has only one 'n'; the combo "Chandler victorian" would need two 'n's,
     * so no phrase should be emitted when only those candidates are supplied.
     */
    public function test_never_emits_phrase_with_signature_mismatch(): void
    {
        $source = 'victoria chandler';
        $matches = [
            'forename' => ['Victorian'],
            'surname'  => ['Chandler'],
        ];
        $patternTokenPositions = [
            ['name' => 'forename', 'pos' => 0],
            ['name' => 'surname',  'pos' => 1],
        ];
        $anagrammer = new Anagrammer($matches);
        $phrases = iterator_to_array($anagrammer->generate($source, $patternTokenPositions));
        $this->assertSame([], $phrases, 'Anagrammer should not emit mismatched overlength phrase.');
    }

    /**
     * If candidates include punctuation (e.g., I.M.), the anagrammer precompute should drop them
     * and thus produce no phrase when only such invalid candidates are provided.
     */
    public function test_drops_punctuation_candidates_and_emits_nothing_if_only_invalid(): void
    {
        $source = 'Iam';
        $matches = [
            'title' => ['I.M.'],
            'surname' => ['-am'],
        ];
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'surname', 'pos' => 1],
        ];
        $anagrammer = new Anagrammer($matches);
        $phrases = iterator_to_array($anagrammer->generate($source, $patternTokenPositions));
        $this->assertSame([], $phrases, 'Anagrammer should ignore punctuation candidates and emit nothing.');
    }
}
