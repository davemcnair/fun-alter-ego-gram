<?php

namespace Tests\Unit;

use App\Services\PhraseBuilderService;
use PHPUnit\Framework\TestCase;

class PhraseBuilderServiceTest extends TestCase
{
    public function test_single_surname_is_capitalized(): void
    {
        $svc = new PhraseBuilderService();
        $words = ['Dr', 'Adam', 'vinci'];
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions);
        $this->assertSame('Dr Adam Vinci', $phrase);
    }

    public function test_multiple_surnames_are_hyphenated_and_capitalized(): void
    {
        $svc = new PhraseBuilderService();
        $words = ['Vicar', 'Dan', 'dim', 'vinci'];
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
            ['name' => 'surname', 'pos' => 3],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions);
        $this->assertSame('Vicar Dan Dim-Vinci', $phrase);
    }

    public function test_double_surname_lists_both_variants_for_display(): void
    {
        $svc = new PhraseBuilderService();
        $words = ['Vicar', 'Dan', 'dim', 'vinci'];
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
            ['name' => 'surname', 'pos' => 3],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions, true);
        $this->assertSame('Vicar Dan Dim-Vinci, Vinci-Dim', $phrase);
    }

    public function test_triple_surname_does_not_list_variants_for_display(): void
    {
        $svc = new PhraseBuilderService();
        $words = ['Sir', 'adam', 'dim', 'vinci', 'mongrel'];
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
            ['name' => 'surname', 'pos' => 3],
            ['name' => 'surname', 'pos' => 4],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions, true);
        $this->assertSame('Sir Adam Dim-Vinci-Mongrel', $phrase);
    }
}
