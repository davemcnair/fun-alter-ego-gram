<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\TargetPattern;
use App\Models\Token;
use App\Services\ListPatternsService;
use App\Services\TargetCreationService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        // Mock WordMatchService to avoid writing to pivot in this test
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
        $this->assertSame('jane ray', $target->normalized_key);
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

    public function test_create_with_filtered_patterns_inserts_target_patterns_and_dispatches_jobs_for_pending(): void
    {
        // Prepare two patterns: one standard (pending) and one exotic (deferred)
        $pStandard = Pattern::create([
            'template' => '{forename}{surname}',
            'popularity_rank' => 1,
            'pattern_type' => 'standard',
            'min_total_length' => 2,
            'forename_count' => 1,
            'surname_count' => 1,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);
        $pExotic = Pattern::create([
            'template' => '{surname}{surname}',
            'popularity_rank' => 2,
            'pattern_type' => 'exotic',
            'min_total_length' => 2,
            'forename_count' => 0,
            'surname_count' => 2,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);

        // Mock ListPatternsService to return these as already-filtered
        $mock = Mockery::mock(ListPatternsService::class);
        $mock->shouldReceive('listWithinMinLength')->andReturn(collect([$pStandard, $pExotic]));
        $mock->shouldReceive('filterPatternsForTarget')->andReturn(collect([$pStandard, $pExotic]));
        $this->app->instance(ListPatternsService::class, $mock);

        // Mock WordMatchService minimal behavior
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('storeNewTargetMatchedTokenSignatureWords')->andReturn(collect());
        $wm->shouldReceive('extractMatchingTokenWordMinimumLengths')->andReturn([[], []]);
        $this->app->instance(WordMatchService::class, $wm);

        // Force async queue path so Bus::fake can see dispatches
        Config::set('search.queue', 'test');
        Bus::fake();

        $svc = app(TargetCreationService::class);
        $result = $svc->create('Jane Ray');

        // We expect 2 TargetPattern rows inserted with appropriate statuses
        $this->assertSame(2, TargetPattern::count());
        $pending = TargetPattern::where('status', 'pending')->get();
        $deferred = TargetPattern::where('status', 'deferred')->get();
        $this->assertCount(1, $pending);
        $this->assertCount(1, $deferred);

        // filtered_count reflects both patterns, pending_count reflects only standard
        $this->assertSame(2, $result['filtered_count']);
        $this->assertSame(1, $result['pending_count']);

        // One FillPatternSignaturesJob dispatched (for the pending pattern)
        Bus::assertDispatched(FillPatternSignaturesJob::class, 1);
    }

    public function test_create_with_invalid_name_throws_422(): void
    {
        $this->bindEmptyPatternsServiceMock();
        $wm = Mockery::mock(WordMatchService::class);
        $this->app->instance(WordMatchService::class, $wm);
        $svc = app(TargetCreationService::class);

        $this->expectException(HttpException::class);
        try {
            $svc->create('!!!');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }
}
