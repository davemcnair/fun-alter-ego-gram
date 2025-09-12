<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private WordMatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WordMatchService::class);
        // Seed a couple of tokens used by tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
        ]);
    }

    public function test_adds_fun_word_not_deferred_for_new_signature(): void
    {
        $tsw = $this->svc->addTokenWord('forename', 'Jane', 'fun');
        $this->assertInstanceOf(TokenSignatureWord::class, $tsw);
        $this->assertFalse($tsw->is_deferred);

        $sig = TokenSignature::first();
        $this->assertNotNull($sig);
        $this->assertEquals('aejn', $sig->signature);
    }

    public function test_adds_non_fun_word_deferred_for_existing_signature(): void
    {
        // First create a signature by adding a fun word
        $this->svc->addTokenWord('forename', 'Jane', 'fun');

        // Now add a non-fun word with same signature
        $tsw2 = $this->svc->addTokenWord('forename', 'enja', 'ok');
        $this->assertTrue((bool)$tsw2->is_deferred, 'Non-fun under existing signature should be deferred');
    }

    public function test_retroactively_defers_first_non_fun_when_fun_exists(): void
    {
        // Add non-fun first
        $nf = $this->svc->addTokenWord('forename', 'enja', 'ok');
        $this->assertFalse((bool)$nf->is_deferred, 'First word of new signature should not be deferred initially');

        // Add a fun word with same signature; should retro-defer first non-fun
        $this->svc->addTokenWord('forename', 'jane', 'fun');
        $nf->refresh();
        $this->assertTrue((bool)$nf->is_deferred, 'First non-fun must be retro-deferred once a fun word exists');
    }

    public function test_idempotent_on_duplicate_word_for_same_signature_and_list(): void
    {
        $w1 = $this->svc->addTokenWord('surname', 'ray', 'ok');
        $w2 = $this->svc->addTokenWord('surname', 'ray', 'ok');
        $this->assertEquals($w1->id, $w2->id);

        $count = TokenSignatureWord::count();
        $this->assertSame(1, $count);
    }

    public function test_returns_null_for_unknown_token_or_empty_signature(): void
    {
        $this->assertNull($this->svc->addTokenWord('nonexistent', 'jane', 'fun'));
        $this->assertNull($this->svc->addTokenWord('forename', '!!!', 'fun'));
    }

    public function test_find_matching_token_signature_words_with_filters_and_boring_flag(): void
    {
        // Seed words
        $this->svc->addTokenWord('forename', 'jane', 'fun'); // not deferred
        $this->svc->addTokenWord('forename', 'enja', 'ok'); // deferred due to existing signature
        $this->svc->addTokenWord('forename', 'li', 'boring'); // new signature, not deferred
        $this->svc->addTokenWord('surname', 'ray', 'ok'); // new signature, not deferred

        $sourceSig = $this->svc->makeSignature('jane li ray');

        // Default: include_boring=false, no list filter => 2 matches (jane fun, ray ok)
        $matches = $this->svc->findMatchingTokenSignatureWords($sourceSig);
        $this->assertCount(2, $matches);

        // Include boring => should include 'li' boring (not deferred)
        $matchesWithBoring = $this->svc->findMatchingTokenSignatureWords($sourceSig, ['include_boring' => true]);
        $this->assertCount(3, $matchesWithBoring);

        // Filter by token forename (default exclude boring)
        $forenameMatches = $this->svc->findMatchingTokenSignatureWords($sourceSig, ['token' => 'forename']);
        $this->assertCount(1, $forenameMatches);
        $this->assertTrue($forenameMatches->every(fn($r) => $r->list_type !== 'boring'));

        // Filter by token forename and include boring
        $forenameMatchesWithBoring = $this->svc->findMatchingTokenSignatureWords($sourceSig, ['token' => 'forename', 'include_boring' => true]);
        $this->assertCount(2, $forenameMatchesWithBoring);

        // Filter by list fun
        $funOnly = $this->svc->findMatchingTokenSignatureWords($sourceSig, ['list' => 'fun']);
        $this->assertCount(1, $funOnly);
        $this->assertSame('fun', $funOnly->first()->list_type);

        // Ensure deferred record is never returned
        $this->assertTrue($matches->every(fn($r) => !$r->is_deferred));
    }


    public function test_extract_matching_token_word_minimum_lengths(): void
    {
        // Adjust min lengths to be distinct
        Token::where('name', 'forename')->update(['min_length' => 3]);
        Token::where('name', 'surname')->update(['min_length' => 2]);

        // Seed words with various signature lengths
        $this->svc->addTokenWord('forename', 'ann', 'ok');   // sig length 3
        $this->svc->addTokenWord('forename', 'anne', 'ok');  // sig length 4
        $this->svc->addTokenWord('surname', 'ry', 'ok');     // len 2
        $this->svc->addTokenWord('surname', 'ray', 'ok');    // len 3

        $tsws = TokenSignatureWord::with('tokenSignature.token')->get();

        $sourceSig = $this->svc->makeSignature('ann ray'); // can cover all above
        [$stored, $matching] = $this->svc->extractMatchingTokenWordMinimumLengths($sourceSig, $tsws);

        // Stored mins come from tokens table
        $forenameId = Token::where('name', 'forename')->first()->id;
        $surnameId = Token::where('name', 'surname')->first()->id;
        $this->assertSame(3, $stored[$forenameId]);
        $this->assertSame(2, $stored[$surnameId]);

        // Matching min lengths: min per token of signature lengths that are subset of source signature
        $this->assertSame(3, $matching[$forenameId]); // min(3,4) => 3
        $this->assertSame(2, $matching[$surnameId]);  // min(2,3) => 2
    }
}
