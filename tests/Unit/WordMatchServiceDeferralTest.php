<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceDeferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 0],
        ]);
    }

    public function test_retroactively_defers_first_non_fun_when_fun_is_added(): void
    {
        $svc = app(WordMatchService::class);

        // First add a non-fun word (creates TokenSignature and should not be deferred because wasRecentlyCreated)
        $w1 = $svc->addTokenWord('forename', 'adam', 'ok');
        $this->assertFalse($w1->is_deferred, 'First non-fun word on new signature should not be deferred');

        // Then add a FUN word on the same signature; this should retroactively defer the first non-fun
        $w2 = $svc->addTokenWord('forename', 'mada', 'fun');
        $this->assertFalse($w2->is_deferred, 'Fun words are never deferred');

        $w1->refresh();
        $this->assertTrue($w1->is_deferred, 'First non-fun should be retroactively deferred when a fun exists');
    }
}
