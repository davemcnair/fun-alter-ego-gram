<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Models\Signature;
use App\Services\SignatureFillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureFillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal tokens used in tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 2],
        ]);
    }

    private function seedTokenSignatureWord(string $tokenName, string $signature, string $list = 'ok'): TokenSignatureWord
    {
        $tokenId = (int)Token::where('name', $tokenName)->firstOrFail()->id;
        $sig = Signature::firstOrCreate(['signature' => $signature], ['length' => strlen($signature)]);
        $ts = TokenSignature::create(['token_id' => $tokenId, 'signature_id' => $sig->id]);
        return TokenSignatureWord::create([
            'token_signature_id' => $ts->id,
            'list_type' => $list,
            'word' => $signature, // value not used by service; keep simple
            'is_deferred' => false,
        ]);
    }

    public function test_generateSignaturePatterns_two_different_tokens_exact_cover(): void
    {
        $svc = app(SignatureFillService::class);
        $forenameId = (int)Token::where('name', 'forename')->first()->id;
        $surnameId = (int)Token::where('name', 'surname')->first()->id;

        // Candidates: forename "jane" => aejn, surname "ray" => ary
        $tswF = $this->seedTokenSignatureWord('forename', 'aejn');
        $tswS = $this->seedTokenSignatureWord('surname', 'ary');

        $targetSig = 'aaejnry'; // sorted signature of "Jane Ray"
        $slots = [0 => $forenameId, 1 => $surnameId];

        $rows = collect([$tswF->fresh('tokenSignature'), $tswS->fresh('tokenSignature')]);
        $out = iterator_to_array($svc->generateSignaturedPatterns($targetSig, $slots, $rows), false);

        $this->assertSame(['{' . $forenameId . ':aejn}{' . $surnameId . ':ary}'], $out);
    }

    public function test_generateSignaturePatterns_repeated_token_uses_same_candidate_twice(): void
    {
        $svc = app(SignatureFillService::class);
        $surnameId = (int)Token::where('name', 'surname')->first()->id;

        // Single candidate that must be used for both surname slots
        $tsw = $this->seedTokenSignatureWord('surname', 'ciinv');

        $targetSig = 'ciinvciinv';
        $slots = [0 => $surnameId, 1 => $surnameId];

        $rows = collect([$tsw->fresh('tokenSignature')]);
        $out = iterator_to_array($svc->generateSignaturedPatterns($targetSig, $slots, $rows), false);

        $this->assertSame(['{' . $surnameId . ':ciinv}{' . $surnameId . ':ciinv}'], $out);
    }

    public function test_generateSignaturePatterns_impossible_yields_nothing(): void
    {
        $svc = app(SignatureFillService::class);
        $forenameId = (int)Token::where('name', 'forename')->first()->id;

        $this->seedTokenSignatureWord('forename', 'adn');
        $targetSig = 'abc';
        $slots = [0 => $forenameId];

        $rows = TokenSignatureWord::with('tokenSignature')->get();
        $out = iterator_to_array($svc->generateSignaturedPatterns($targetSig, $slots, $rows), false);

        $this->assertSame([], $out);
    }
}
