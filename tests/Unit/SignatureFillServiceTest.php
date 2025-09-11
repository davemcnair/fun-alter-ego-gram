<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Services\SignatureFillService;
use App\Traits\HelpsMatchWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureFillServiceTest extends TestCase
{
    use RefreshDatabase;
    use HelpsMatchWords;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed required tokens used by Pattern::parsePatternTokenSlotPositions
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
        ]);
    }

    private function addTSW(string $tokenName, string $word, string $list = 'fun', bool $deferred = false): TokenSignatureWord
    {
        $sig = $this->makeSignature($word);
        $token = Token::where('name', $tokenName)->firstOrFail();
        $ts = TokenSignature::firstOrCreate(['token_id' => $token->id, 'signature' => $sig]);
        return TokenSignatureWord::create([
            'token_signature_id' => $ts->id,
            'word' => strtolower($word),
            'list_type' => $list,
            'is_deferred' => $deferred,
        ]);
    }

    public function test_generates_signature_patterns_exact_cover_in_slot_order(): void
    {
        // Pattern: {forename}{surname}
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $slots = Pattern::parsePatternTokenSlotPositions($pattern->template);

        // Candidates: forename aadm (Adam), surname ciinv (Vinci)
        $tsw1 = $this->addTSW('forename', 'adam', 'fun'); // aadm
        $tsw2 = $this->addTSW('surname', 'vinci', 'fun'); // ciinv

        $srcSig = $this->makeSignature('adam vinci');

        $svc = app(SignatureFillService::class);
        $out = iterator_to_array($svc->generateSignaturePatterns($srcSig, $slots, [$tsw1->id, $tsw2->id]), false);

        // Expect token IDs in braces in increasing slot order
        $forenameId = Token::where('name','forename')->first()->id;
        $surnameId = Token::where('name','surname')->first()->id;
        $this->assertSame(['{' . $forenameId . ':aadm}{' . $surnameId . ':ciinv}'], $out);
    }

    public function test_duplicate_token_run_allows_reuse_of_same_candidate(): void
    {
        // Pattern: {surname}{surname}
        $pattern = Pattern::create(['template' => '{surname:2}']);
        $slots = Pattern::parsePatternTokenSlotPositions($pattern->template);

        // Candidate: 'vinci' usable twice
        $tsw = $this->addTSW('surname', 'vinci', 'fun'); // ciinv

        $srcSig = $this->makeSignature('vinci vinci');
        $svc = app(SignatureFillService::class);
        $out = iterator_to_array($svc->generateSignaturePatterns($srcSig, $slots, [$tsw->id]), false);

        $surnameId = Token::where('name','surname')->first()->id;
        $this->assertSame(['{' . $surnameId . ':ciinv}{' . $surnameId . ':ciinv}'], $out);
    }

    public function test_impossible_case_yields_no_results(): void
    {
        $pattern = Pattern::create(['template' => '{forename}']);
        $slots = Pattern::parsePatternTokenSlotPositions($pattern->template);

        $tsw = $this->addTSW('forename', 'dan', 'fun'); // adn
        $srcSig = $this->makeSignature('zzz');

        $svc = app(SignatureFillService::class);
        $out = iterator_to_array($svc->generateSignaturePatterns($srcSig, $slots, [$tsw->id]), false);
        $this->assertSame([], $out);
    }

    public function test_accepts_source_signature_string(): void
    {
        // Ensure that even if we pass a name with spaces/case, we use its signature letters
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $slots = Pattern::parsePatternTokenSlotPositions($pattern->template);

        $this->addTSW('forename', 'mary', 'fun'); // amry
        $this->addTSW('surname', 'jane', 'fun');  // aejn
        $ids = TokenSignatureWord::pluck('id')->all();

        $svc = app(SignatureFillService::class);
        $out = iterator_to_array($svc->generateSignaturePatterns($this->makeSignature('Mary Jane'), $slots, $ids), false);

        $forenameId = Token::where('name','forename')->first()->id;
        $surnameId = Token::where('name','surname')->first()->id;
        $opt1 = '{' . $forenameId . ':amry}{' . $surnameId . ':aejn}';
        $opt2 = '{' . $forenameId . ':aejn}{' . $surnameId . ':amry}';
        $this->assertTrue(in_array($opt1, $out, true) || in_array($opt2, $out, true), 'Expected one of the valid fills to be produced');
    }
}
