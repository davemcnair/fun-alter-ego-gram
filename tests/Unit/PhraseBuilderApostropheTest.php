<?php

namespace Tests\Unit;

use App\Services\PhraseBuilderService;
use PHPUnit\Framework\TestCase;

class PhraseBuilderApostropheTest extends TestCase
{
    public function test_surname_with_apostrophe_is_title_cased(): void
    {
        $svc = new PhraseBuilderService();
        $words = ['Mr', 'Uriah', "O'Yak"];
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
        ];
        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions);
        $this->assertSame("Mr Uriah O'Yak", $phrase);
    }
}
