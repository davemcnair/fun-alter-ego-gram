<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Services\WordCatalog;
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
        $svc = app(WordCatalog::class);

        $w1 = $svc->add('forename', 'adam', 'ok');
        $this->assertTrue($w1->is_deferred, 'OK words start deferred');

        // Then add a FUN word on the same signature; this should retroactively defer the first non-fun
        $w2 = $svc->add('forename', 'mada', 'fun');
        $this->assertFalse($w2->is_deferred, 'Fun words are never deferred');

        $w1->refresh();
        $this->assertTrue($w1->is_deferred, 'First non-fun should be retroactively deferred when a fun exists');
    }
}
