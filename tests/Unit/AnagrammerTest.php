<?php

namespace Tests\Unit;

use App\Services\Anagrammer;
use PHPUnit\Framework\TestCase;

class AnagrammerTest extends TestCase
{
    /**
     * Prove that Anagrammer can find "Dr Adam vinci" for source "David McNair"
     * using the pattern {title}{forename}{surname} when suitable candidates exist.
     */
    public function test_generates_dr_adam_vinci_for_david_mcnair(): void
    {
        $source = 'David McNair';
        $matches = [
            'title' => ['Dr'],
            'forename' => ['Adam'],
            'surname' => ['vinci'],
        ];
        $anagrammer = new Anagrammer($matches);
        $slots = [['name' => 'title', 'pos' => 0], ['name' => 'forename', 'pos' => 1], ['name' => 'surname', 'pos' => 2]];
        $phrases = iterator_to_array($anagrammer->generate($source, $slots));
        $lower = array_map('strtolower', $phrases);
        $this->assertContains('dr adam vinci', $lower);
    }

    /**
     * Prove that Anagrammer can find "Vicar Dan dim" for source "David McNair"
     * using the pattern {title}{forename}{surname} when suitable candidates exist.
     */
    public function test_generates_vicar_dan_dim_for_david_mcnair(): void
    {
        $source = 'David McNair';
        $matches = [
            'title' => ['Vicar'],
            'forename' => ['Dan'],
            'surname' => ['dim'],
        ];
        $anagrammer = new Anagrammer($matches);
        $slots = [['name' => 'title', 'pos' => 0], ['name' => 'forename', 'pos' => 1], ['name' => 'surname', 'pos' => 2]];
        $phrases = iterator_to_array($anagrammer->generate($source, $slots));
        $lower = array_map('strtolower', $phrases);
        $this->assertContains('vicar dan dim', $lower);
    }

    /**
     * When multiple candidates are provided per token, ensure both target alter egos
     * are generated for source "David McNair" with pattern {title}{forename}{surname}.
     */
    public function test_generates_both_target_phrases_with_combined_matches(): void
    {
        $source = 'David McNair';
        $matches = [
            'title' => ['Dr', 'Vicar'],
            'forename' => ['Adam', 'Dan'],
            'surname' => ['dim', 'vinci'],
        ];
        $anagrammer = new Anagrammer($matches);
        $slots = [
            ['name' => 'title', 'pos' => 0],
            ['name' => 'forename', 'pos' => 1],
            ['name' => 'surname', 'pos' => 2],
        ];
        $phrases = iterator_to_array($anagrammer->generate($source, $slots));
        $lower = array_map('strtolower', $phrases);
        $this->assertContains('dr adam vinci', $lower, 'Expected to generate: Dr Adam vinci');
        $this->assertContains('vicar dan dim', $lower, 'Expected to generate: Vicar Dan dim');
    }
}
