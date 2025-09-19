<?php

namespace Tests\Unit;

use App\Models\Signature;
use App\Models\Token;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchMinLengthsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 99],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 7],
        ]);
    }

    public function test_extract_min_lengths_reads_signature_relation_length(): void
    {
        $svc = app(WordMatchService::class);

        // Add candidate words
        $svc->addTokenWord('forename', 'ada', 'ok'); // sig: aad (len 3)
        $svc->addTokenWord('surname', 'vinci', 'ok'); // sig: ciinv (len 5)

        // Target signature 'aacddiimnrv' (David McNair) length 11
        $sig = Signature::firstOrCreate(['signature' => 'aacddiimnrv'], [
            'length' => 11,
            'a_count' => 2,
            'c_count' => 1,
            'd_count' => 2,
            'i_count' => 2,
            'm_count' => 1,
            'n_count' => 1,
            'r_count' => 1,
            'v_count' => 1,
        ]);

        $matches = $svc->findMatchingTokenSignatureWords($sig);
        [$stored, $matched] = $svc->extractTargetTokenSignatureWordMinimumLengths($matches);

        // Stored comes from tokens.min_length (forename intentionally set very high)
        $this->assertSame(99, $stored[Token::where('name','forename')->first()->id] ?? null);
        $this->assertSame(7, $stored[Token::where('name','surname')->first()->id] ?? null);

        // Matched comes from actual TokenSignature->Signature->length
        $this->assertSame(3, $matched[Token::where('name','forename')->first()->id] ?? null);
        $this->assertSame(5, $matched[Token::where('name','surname')->first()->id] ?? null);
    }
}
