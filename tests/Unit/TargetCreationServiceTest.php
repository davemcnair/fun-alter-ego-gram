<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\Token;
use App\Services\ListPatternsService;
use App\Services\TargetService;
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

    public function test_create_with_invalid_name_throws_422(): void
    {
        $this->bindEmptyPatternsServiceMock();
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('linkMatchesToTarget');
        $this->app->instance(WordMatchService::class, $wm);
        $svc = app(TargetService::class);

        $this->expectException(HttpException::class);
        try {
            $svc->create('!!!');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_unsatisfiable_patterns_are_not_inserted_as_pending(): void
    {
        // Ensure fill jobs don't run synchronously during the test
        Config::set('search.queue', 'test');
        Bus::fake();

        // Create two standard patterns: one forename-only, one requiring surname
        $pForenameOnly = Pattern::create([
            'template' => '{forename}',
            'popularity_rank' => 10,
            'pattern_type' => 'standard',
            'min_total_length' => 2,
            'forename_count' => 1,
            'surname_count' => 0,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);
        $pBoth = Pattern::create([
            'template' => '{forename}{surname}',
            'popularity_rank' => 11,
            'pattern_type' => 'standard',
            'min_total_length' => 4,
            'forename_count' => 1,
            'surname_count' => 1,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);

        // Mock WordMatchService to simulate no surname candidates for the target
        $forenameId = (int)Token::where('name', 'forename')->first()->id;
        $surnameId  = (int)Token::where('name', 'surname')->first()->id;
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('linkMatchesToTarget');
        // No matched words are actually needed to be returned for pivot insert
        $wm->shouldReceive('findMatchingTokenSignatureWords')
            ->andReturn(collect());
        // Provide stored mins (from tokens) and matched mins (only forename has matches)
        $wm->shouldReceive('extractTargetTokenSignatureWordMinimumLengths')
            ->andReturn([
                // stored mins
                [ $forenameId => 2, $surnameId => 2 ],
                // matched mins (surname missing => unsatisfiable where required)
                [ $forenameId => 3 ],
            ]);
        $this->app->instance(WordMatchService::class, $wm);

        // Use real ListPatternsService so filtering logic is exercised
        $svc = app(TargetService::class);

        $target = $svc->create('Jane');

        /** @var Target $target */
        $pending = TargetPattern::where('target_id', $target->id)
            ->where('status', 'pending')
            ->get();

        $this->assertCount(1, $pending, 'Only one pending pattern should be inserted');
        $this->assertSame($pForenameOnly->id, $pending->first()->pattern_id, 'Unsatisfiable pattern must not be inserted as pending');

        // Ensure a fill job would only be dispatched for the kept pattern
        Bus::assertDispatched(FillPatternSignaturesJob::class, 1);
    }
}
