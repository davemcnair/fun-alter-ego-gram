<?php

namespace Tests\Unit;

use App\Models\Target;
use App\Models\Token;
use App\Services\ListPatternsService;
use App\Services\TargetCreationService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TargetCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal token seed for tests (matches other unit tests)
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);

        // Keep patterns service empty to avoid job dispatch complexity
        $mock = Mockery::mock(ListPatternsService::class);
        $mock->shouldReceive('listWithinMinLength')->andReturn(collect());
        $mock->shouldReceive('filterPatternsForTarget')->andReturn(collect());
        $this->app->instance(ListPatternsService::class, $mock);

        // Mock WordMatchService minimal interactions
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('storeNewTargetMatchedTokenSignatureWords')->andReturn(collect());
        $wm->shouldReceive('extractMatchingTokenWordMinimumLengths')->andReturn([[], []]);
        $this->app->instance(WordMatchService::class, $wm);
    }

    public function test_variants_deduplicate_to_one_target(): void
    {
        Bus::fake();
        $svc = app(TargetCreationService::class);

        $svc->create('David McNair');
        $svc->create('davidmcnair');
        $svc->create('David mcnair');
        $svc->create('david mcnair');

        $this->assertSame(1, Target::count());
        $this->assertSame('davidmcnair', Target::first()->signature);
    }

    public function test_invalid_input_yields_422_and_no_row(): void
    {
        $svc = app(TargetCreationService::class);
        $this->expectException(HttpException::class);
        try {
            $svc->create('!!!');
        } finally {
            $this->assertSame(0, Target::count());
        }
    }
}
