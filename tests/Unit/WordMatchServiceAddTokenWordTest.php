<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceAddTokenWordTest extends TestCase
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
        $fun = $this->svc->addTokenWord('forename', 'jane', 'fun');
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
}
