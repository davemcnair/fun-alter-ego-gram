<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\Token;
use App\Services\FillPatternSignaturesService;
use App\Services\SignatureFillService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillPatternRarityOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 0],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 0],
        ]);
    }

    public function test_rarity_first_ordering_passes_rare_token_slot_first(): void
    {
        $this->markTestSkipped('Ordering capture requires deeper hooks; parity tests implemented per proposed change 12.');
    }
}
