<?php

namespace Tests\Unit;

use App\Models\Word;
use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceTest extends TestCase
{
    use RefreshDatabase;
    use HelpsMatchWords;

    private WordMatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WordMatchService::class);
    }

    private function addWord(string $word, string $token, string $list, bool $useForSearch = true): Word
    {
        return Word::create([
            'word' => $word,
            'token_type' => $token,
            'list_type' => $list,
            'use_for_search' => $useForSearch,
            'signature' => $this->makeSignature($word),
        ]);
    }

    public function test_groups_by_token_and_list_and_excludes_boring_by_default(): void
    {
        $src = 'Mary Jane';
        $sig = $this->makeSignature($src); // aaejmnry

        // forename fun: jane
        $jane = $this->addWord('jane', 'forename', 'fun', true);
        // surname ok: ray
        $ray = $this->addWord('ray', 'surname', 'ok', true);
        // surname boring: mary (subset, but should be excluded by default)
        $mary = $this->addWord('mary', 'surname', 'boring', true);
        // adjective fun: mean (subset)
        $mean = $this->addWord('mean', 'adjective', 'fun', true);
        // a candidate that is NOT subset: 'zoo' (not subset)
        $zoo = $this->addWord('zoo', 'noun', 'fun', true);

        $groups = $this->svc->findMatches($sig, [ 'include_boring' => false ]);

        // Basic presence
        $this->assertArrayHasKey('forename', $groups);
        $this->assertArrayHasKey('surname', $groups);
        $this->assertArrayHasKey('adjective', $groups);
        $this->assertArrayNotHasKey('noun', $groups, 'Non-subset token should not be present');

        // Boring excluded
        $this->assertArrayHasKey('ok', $groups['surname']);
        $this->assertArrayNotHasKey('boring', $groups['surname']);

        // Check entries and shape
        $this->assertSame('jane', $groups['forename']['fun'][0]['word']);
        $this->assertSame($this->makeSignature('jane'), $groups['forename']['fun'][0]['signature']);
        $this->assertSame('ray', $groups['surname']['ok'][0]['word']);
        $this->assertSame('mean', $groups['adjective']['fun'][0]['word']);
    }

    public function test_include_boring_true_includes_boring_when_no_list_filter(): void
    {
        $sig = $this->makeSignature('Mary Jane');
        $this->addWord('mary', 'surname', 'boring', true);

        $groups = $this->svc->findMatches($sig, [ 'include_boring' => true ]);
        $this->assertArrayHasKey('surname', $groups);
        $this->assertArrayHasKey('boring', $groups['surname']);
        $this->assertSame('mary', $groups['surname']['boring'][0]['word']);
    }

    public function test_token_filter_limits_results_to_that_token(): void
    {
        $sig = $this->makeSignature('Mary Jane');
        $this->addWord('jane', 'forename', 'fun', true);
        $this->addWord('ray', 'surname', 'ok', true);

        $groups = $this->svc->findMatches($sig, [ 'token' => 'forename', 'include_boring' => true ]);
        $this->assertArrayHasKey('forename', $groups);
        $this->assertArrayNotHasKey('surname', $groups);
    }

    public function test_list_filter_limits_results_to_that_list_even_if_include_boring_true(): void
    {
        $sig = $this->makeSignature('Mary Jane');
        $this->addWord('mary', 'surname', 'boring', true);
        $this->addWord('ray', 'surname', 'ok', true);

        $groups = $this->svc->findMatches($sig, [ 'list' => 'ok', 'include_boring' => true ]);
        $this->assertArrayHasKey('surname', $groups);
        $this->assertArrayHasKey('ok', $groups['surname']);
        $this->assertArrayNotHasKey('boring', $groups['surname'], 'List filter should override include_boring');
        $this->assertSame('ray', $groups['surname']['ok'][0]['word']);
    }

    public function test_subset_respects_letter_multiplicity_and_length_guard(): void
    {
        // Source with two n's required for "anna"
        $sig = $this->makeSignature('anna');
        $this->addWord('anna', 'forename', 'fun', true);   // exact match
        $this->addWord('ana', 'forename', 'ok', true);     // subset
        $this->addWord('annas', 'forename', 'fun', true);  // longer than source (should be filtered by LENGTH and subset)
        $this->addWord('nana', 'forename', 'ok', false);   // subset but not use_for_search

        $groups = $this->svc->findMatches($sig, [ 'include_boring' => true ]);

        $this->assertArrayHasKey('forename', $groups);
        $this->assertArrayHasKey('fun', $groups['forename']);
        $this->assertArrayHasKey('ok', $groups['forename']);

        $funWords = array_column($groups['forename']['fun'], 'word');
        $okWords = array_column($groups['forename']['ok'], 'word');

        $this->assertContains('anna', $funWords);
        $this->assertContains('ana', $okWords);
        $this->assertNotContains('annas', $funWords, 'Longer-than-source word must be excluded');
        $this->assertNotContains('nana', $okWords, 'Rows with use_for_search=0 must be excluded');
    }
}
