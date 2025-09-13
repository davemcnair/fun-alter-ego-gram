<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Token;
use App\Services\ListPatternsService;
use App\Services\TargetCreationService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class TargetCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal token seed for tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    private function bindEmptyPatternsServiceMock(): void
    {
        $mock = Mockery::mock(ListPatternsService::class);
        $mock->shouldReceive('listWithinMinLength')->andReturn(collect());
        $mock->shouldReceive('filterPatternsForTarget')->andReturn(collect());
        $this->app->instance(ListPatternsService::class, $mock);
    }

    public function test_create_links_target_and_matches_words_with_no_patterns_dispatches_no_jobs(): void
    {
        $this->bindEmptyPatternsServiceMock();
        Bus::fake();

        // Mock WordMatchService to avoid writing to target_matched_words (SQLite FK issues)
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('storeNewTargetMatchedTokenSignatureWords')
           ->once()
           ->withArgs(function($target, $includeBoring) { return $includeBoring === false; })
           ->andReturn(collect());
        $wm->shouldReceive('extractMatchingTokenWordMinimumLengths')->once()->andReturn([[], []]);
        $this->app->instance(WordMatchService::class, $wm);

        // Execute
        $svc = app(TargetCreationService::class);
        $result = $svc->create('Jane Ray');

        // Assertions on return payload
        $this->assertArrayHasKey('target', $result);
        $this->assertArrayHasKey('filtered_count', $result);
        $this->assertArrayHasKey('pending_count', $result);
        $this->assertSame(0, $result['filtered_count']);
        $this->assertSame(0, $result['pending_count']);

        // Target was created and moved to running
        $target = $result['target']->fresh();
        $this->assertNotNull($target);
        $this->assertSame('aaejnry', $target->signature); // sorted signature of "Jane Ray"
        $this->assertSame('running', $target->status);

        // No jobs dispatched when there are no pending patterns
        Bus::assertNotDispatched(FillPatternSignaturesJob::class);
    }

    public function test_create_respects_include_boring_flag_for_matched_words(): void
    {
        $this->bindEmptyPatternsServiceMock();
        Bus::fake();

        // Mock WordMatchService and assert include_boring flag is propagated
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('storeNewTargetMatchedTokenSignatureWords')
           ->once()
           ->withArgs(function($target, $includeBoring) { return $includeBoring === false; })
           ->andReturn(collect());
        $wm->shouldReceive('extractMatchingTokenWordMinimumLengths')->once()->andReturn([[], []]);
        $this->app->instance(WordMatchService::class, $wm);

        $svc = app(TargetCreationService::class);
        $svc->create('li', false);

        // Now expect true on the next call
        $wm2 = Mockery::mock(WordMatchService::class);
        $wm2->shouldReceive('storeNewTargetMatchedTokenSignatureWords')
            ->once()
            ->withArgs(function($target, $includeBoring) { return $includeBoring === true; })
            ->andReturn(collect());
        $wm2->shouldReceive('extractMatchingTokenWordMinimumLengths')->once()->andReturn([[], []]);
        $this->app->instance(WordMatchService::class, $wm2);

        $svc = app(TargetCreationService::class);
        $svc->create('li', true);

        // No pattern jobs are dispatched in either case since we mocked patterns service to empty
        Bus::assertNotDispatched(FillPatternSignaturesJob::class);
    }
}
