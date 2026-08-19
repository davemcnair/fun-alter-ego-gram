<?php

namespace Tests\Unit;

use App\Models\Signature;
use App\Models\Token;
use App\Services\WordCatalog;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WordMatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WordCatalog $catalog;
    protected WordMatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable cache for these tests per issue requirement
        Config::set('search.enable_match_cache', false);
        $this->catalog = app(WordCatalog::class);
        $this->svc = app(WordMatchService::class);

        // Seed minimal tokens used throughout tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
        ]);
    }

    public function test_basic_subset_and_defaults_exclude_boring_and_deferred(): void
    {
        // Target signature for 'adam' -> aadm
        $targetSignature = Signature::firstOrCreate(['signature' => 'aadm'], ['length' => 4, 'a_count' => 2, 'd_count' => 1, 'm_count' => 1]);

        // Create matching candidates under forename
        // New signature 'aadm' with ok (not deferred since signature is newly created)
        $w1 = $this->catalog->add('forename', 'adam', 'ok');
        // Another subset 'aad' (shorter) ok
        $w2 = $this->catalog->add('forename', 'ada', 'ok');
        // Boring subset that should be excluded by default
        $w3 = $this->catalog->add('forename', 'am', 'boring');
        // Non-subset (has n) should never match
        $w4 = $this->catalog->add('forename', 'anna', 'ok');

        // Create a deferred candidate intentionally: create fun first for signature, then ok.
        $this->catalog->add('forename', 'adam', 'fun');
        $deferred = $this->catalog->add('forename', 'dama', 'ok'); // same signature as 'adam', should be deferred

        // Also add a surname subset candidate
        $ws = $this->catalog->add('surname', 'dam', 'ok');

        $matches = $this->svc->findMatchingTokenSignatureWords($targetSignature);

        // Assertions: contains w2, ws; excludes boring w3; excludes non-subset w4; excludes deferred
        $ids = $matches->pluck('id')->all();
        $this->assertContains($w2->id, $ids);
        $this->assertContains($ws->id, $ids);
        $this->assertNotContains($w3->id, $ids, 'boring should be excluded by default');
        $this->assertNotContains($w4->id, $ids, 'non-subset should not match');
        $this->assertNotContains($deferred->id, $ids, 'deferred non-fun word should be excluded');
    }

    public function test_include_boring_true_includes_boring_words(): void
    {
        $targetSignature = Signature::firstOrCreate(['signature' => 'aadm'], ['length' => 4, 'a_count' => 2, 'd_count' => 1, 'm_count' => 1]);
        $ok = $this->catalog->add('forename', 'adam', 'ok');
        $boring = $this->catalog->add('forename', 'am', 'boring');

        $matches = $this->svc->findMatchingTokenSignatureWords($targetSignature, ['include_boring' => true]);
        $ids = $matches->pluck('id')->all();
        $this->assertContains($ok->id, $ids);
        $this->assertContains($boring->id, $ids);
    }

    public function test_list_filter_overrides_and_limits_to_specific_list(): void
    {
        $targetSignature = Signature::firstOrCreate(['signature' => 'aadm'], ['length' => 4, 'a_count' => 2, 'd_count' => 1, 'm_count' => 1]);
        // Use different matching signatures for OK vs FUN to avoid retroactive deferral of the OK word
        $ok = $this->catalog->add('forename', 'ada', 'ok');     // signature 'aad'
        $fun = $this->catalog->add('forename', 'adam', 'fun');  // signature 'aadm'
        $boring = $this->catalog->add('forename', 'am', 'boring');

        $matchesFunOnly = $this->svc->findMatchingTokenSignatureWords($targetSignature, ['list' => 'fun']);
        $idsFun = $matchesFunOnly->pluck('id')->all();
        $this->assertContains($fun->id, $idsFun);
        $this->assertNotContains($ok->id, $idsFun);
        $this->assertNotContains($boring->id, $idsFun);

        $matchesOkOnly = $this->svc->findMatchingTokenSignatureWords($targetSignature, ['list' => 'ok']);
        $idsOk = $matchesOkOnly->pluck('id')->all();
        $this->assertContains($ok->id, $idsOk);
        $this->assertNotContains($fun->id, $idsOk);
        $this->assertNotContains($boring->id, $idsOk);
    }

    public function test_token_filter_limits_to_specific_token(): void
    {
        $targetSignature = Signature::firstOrCreate(['signature' => 'aadm'], ['length' => 4, 'a_count' => 2, 'd_count' => 1, 'm_count' => 1]);
        $forenameOk = $this->catalog->add('forename', 'adam', 'ok');
        $surnameOk = $this->catalog->add('surname', 'dam', 'ok');

        $matchesForenameOnly = $this->svc->findMatchingTokenSignatureWords($targetSignature, ['token' => 'forename']);
        $idsA = $matchesForenameOnly->pluck('id')->all();
        $this->assertContains($forenameOk->id, $idsA);
        $this->assertNotContains($surnameOk->id, $idsA);

        $matchesSurnameOnly = $this->svc->findMatchingTokenSignatureWords($targetSignature, ['token' => 'surname']);
        $idsB = $matchesSurnameOnly->pluck('id')->all();
        $this->assertContains($surnameOk->id, $idsB);
        $this->assertNotContains($forenameOk->id, $idsB);
    }

    public function test_zero_letter_counts_are_enforced(): void
    {
        // Target with only b's should not match any word containing 'a'
        $targetSignature = Signature::firstOrCreate(['signature' => 'bbb'], ['length' => 3, 'b_count' => 3]);
        $this->catalog->add('forename', 'bb', 'ok');       // should match
        $aWord = $this->catalog->add('forename', 'ab', 'ok'); // should not match (has 'a')

        $matches = $this->svc->findMatchingTokenSignatureWords($targetSignature);
        $words = $matches->pluck('word')->all();
        $this->assertContains('bb', $words);
        $this->assertNotContains('ab', $words);
    }
}
