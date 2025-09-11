<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
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
        // Seed tokens required for this test file
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
            ['name' => 'adjective','prio' => 3, 'min_length' => 2],
            ['name' => 'noun',     'prio' => 4, 'min_length' => 1],
        ]);
    }

    private function addTSW(string $token, string $word, string $list): TokenSignatureWord
    {
        $sig = $this->makeSignature($word);
        $tok = Token::where('name', $token)->firstOrFail();
        $ts = TokenSignature::firstOrCreate(['token_id' => $tok->id, 'signature' => $sig]);
        return TokenSignatureWord::create([
            'token_signature_id' => $ts->id,
            'word' => strtolower($word),
            'list_type' => $list,
            'is_deferred' => false,
        ]);
    }

    public function test_groups_by_token_and_list_and_excludes_boring_by_default(): void
    {
        $src = 'Mary Jane';
        $sig = $this->makeSignature($src); // aaejmnry

        // forename fun: jane
        $this->addTSW('forename', 'jane', 'fun');
        // surname ok: ray
        $this->addTSW('surname', 'ray', 'ok');
        // surname boring: mary (subset, but should be excluded by default)
        $this->addTSW('surname', 'mary', 'boring');
        // adjective fun: mean (subset)
        $this->addTSW('adjective', 'mean', 'fun');
        // a candidate that is NOT subset: 'zoo' (not subset)
        $this->addTSW('noun', 'zoo', 'fun');

        $groups = $this->svc->findMatchingTokenSignatureWords($sig, [ 'include_boring' => false ]);

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
        $this->addTSW('surname', 'mary', 'boring');

        $groups = $this->svc->findMatchingTokenSignatureWords($sig, [ 'include_boring' => true ]);
        $this->assertArrayHasKey('surname', $groups);
        $this->assertArrayHasKey('boring', $groups['surname']);
        $this->assertSame('mary', $groups['surname']['boring'][0]['word']);
    }

    public function test_token_filter_limits_results_to_that_token(): void
    {
        $sig = $this->makeSignature('Mary Jane');
        $this->addTSW('forename', 'jane', 'fun');
        $this->addTSW('surname', 'ray', 'ok');

        $groups = $this->svc->findMatchingTokenSignatureWords($sig, [ 'token' => 'forename', 'include_boring' => true ]);
        $this->assertArrayHasKey('forename', $groups);
        $this->assertArrayNotHasKey('surname', $groups);
    }

    public function test_list_filter_limits_results_to_that_list_even_if_include_boring_true(): void
    {
        $sig = $this->makeSignature('Mary Jane');
        $this->addTSW('surname', 'mary', 'boring');
        $this->addTSW('surname', 'ray', 'ok');

        $groups = $this->svc->findMatchingTokenSignatureWords($sig, [ 'list' => 'ok', 'include_boring' => true ]);
        $this->assertArrayHasKey('surname', $groups);
        $this->assertArrayHasKey('ok', $groups['surname']);
        $this->assertArrayNotHasKey('boring', $groups['surname'], 'List filter should override include_boring');
        $this->assertSame('ray', $groups['surname']['ok'][0]['word']);
    }

    public function test_subset_respects_letter_multiplicity_and_length_guard(): void
    {
        // Source with two n's required for "anna"
        $sig = $this->makeSignature('anna');
        $this->addTSW('forename', 'anna', 'fun');   // exact match
        $this->addTSW('forename', 'ana', 'ok');     // subset
        $this->addTSW('forename', 'annas', 'fun');  // longer than source (should be filtered by LENGTH and subset)
        // legacy use_for_search semantics do not apply on signature tables; emulate exclusion by not inserting 'nana'

        $groups = $this->svc->findMatchingTokenSignatureWords($sig, [ 'include_boring' => true ]);

        $this->assertArrayHasKey('forename', $groups);
        $this->assertArrayHasKey('fun', $groups['forename']);
        $this->assertArrayHasKey('ok', $groups['forename']);

        $funWords = array_column($groups['forename']['fun'], 'word');
        $okWords = array_column($groups['forename']['ok'], 'word');

        $this->assertContains('anna', $funWords);
        $this->assertContains('ana', $okWords);
        $this->assertNotContains('annas', $funWords, 'Longer-than-source word must be excluded');
    }
}
