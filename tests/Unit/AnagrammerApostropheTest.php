<?php

namespace Tests\Unit;

use App\Services\Anagrammer;
use PHPUnit\Framework\TestCase;

class AnagrammerApostropheTest extends TestCase
{
    public function test_generates_mr_uriah_oyak_for_maria_khoury(): void
    {
        $source = "Maria Khoury";
        $matches = [
            'title' => ['Mr'],
            'forename' => ['Uriah'],
            'surname' => ["O'Yak"],
        ];
        $anagrammer = new Anagrammer($matches);
        $patternTokenPositions = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
        ];
        $phrases = iterator_to_array($anagrammer->generate($source, $patternTokenPositions));
        $this->assertNotEmpty($phrases);
        $lower = array_map('strtolower', $phrases);
        $this->assertContains("mr uriah o'yak", $lower);
    }
}
