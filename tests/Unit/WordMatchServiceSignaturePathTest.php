<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceSignaturePathTest extends TestCase
{
    use RefreshDatabase;
    use HelpsMatchWords;

    private WordMatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WordMatchService::class);

        // Ensure some tokens exist
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
            ['name' => 'adjective','prio' => 3, 'min_length' => 2],
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

    public function test_groups_and_filters_on_signature_tables(): void
    {
        $src = 'Mary Jane';
        $sig = $this->makeSignature($src); // aaejmnry

        // Matching subsets
        $this->addTSW('forename', 'jane', 'fun');
        $this->addTSW('surname', 'ray', 'ok');
        $this->addTSW('adjective', 'mean', 'fun');

        // Boring should be excluded unless include_boring=true
        $this->addTSW('surname', 'mary', 'boring');

        // Non-subset should be filtered by isSubset
        $this->addTSW('adjective', 'zoo', 'fun');

        $groups = $this->svc->findMatchingTokenSignatureWords($sig, [ 'include_boring' => false ]);

        $this->assertArrayHasKey('forename', $groups);
        $this->assertArrayHasKey('surname', $groups);
        $this->assertArrayHasKey('adjective', $groups);
        $this->assertArrayNotHasKey('boring', $groups['surname'] ?? []);

        $this->assertSame('jane', $groups['forename']['fun'][0]['word']);
        $this->assertSame('ray', $groups['surname']['ok'][0]['word']);
        $this->assertSame('mean', $groups['adjective']['fun'][0]['word']);

        // include boring
        $groups2 = $this->svc->findMatchingTokenSignatureWords($sig, [ 'include_boring' => true ]);
        $this->assertArrayHasKey('boring', $groups2['surname']);
        $this->assertSame('mary', $groups2['surname']['boring'][0]['word']);

        // token filter
        $onlyForename = $this->svc->findMatchingTokenSignatureWords($sig, [ 'token' => 'forename', 'include_boring' => true ]);
        $this->assertArrayHasKey('forename', $onlyForename);
        $this->assertArrayNotHasKey('surname', $onlyForename);

        // list filter overrides include_boring
        $okOnly = $this->svc->findMatchingTokenSignatureWords($sig, [ 'list' => 'ok', 'include_boring' => true ]);
        $this->assertArrayHasKey('surname', $okOnly);
        $this->assertArrayHasKey('ok', $okOnly['surname']);
        $this->assertArrayNotHasKey('boring', $okOnly['surname']);
    }

    public function test_length_guard_and_subset_on_signature_tables(): void
    {
        $srcSig = $this->makeSignature('anna');
        $this->addTSW('forename', 'anna', 'fun');
        $this->addTSW('forename', 'ana', 'ok');
        $this->addTSW('forename', 'annas', 'fun'); // longer than source

        $groups = $this->svc->findMatchingTokenSignatureWords($srcSig, [ 'include_boring' => true ]);
        $fun = array_column($groups['forename']['fun'] ?? [], 'word');
        $ok  = array_column($groups['forename']['ok'] ?? [], 'word');

        $this->assertContains('anna', $fun);
        $this->assertContains('ana', $ok);
        $this->assertNotContains('annas', $fun);
    }
}
