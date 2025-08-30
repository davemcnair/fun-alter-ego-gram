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
        $slots = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $slots);
        $this->assertSame('Dr Adam Vinci', $phrase);
    }

    public function test_multiple_surnames_are_hyphenated_and_capitalized(): void
    {
        $svc = new PhraseBuilderService();
        $words = ['Vicar', 'Dan', 'dim', 'vinci'];
        $slots = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
            ['name' => 'surname', 'pos' => 3],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $slots);
        $this->assertSame('Vicar Dan Dim-Vinci', $phrase);
    }
}
