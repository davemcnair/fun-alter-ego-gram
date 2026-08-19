<?php

namespace Tests\Unit;

use App\Enums\TargetPatternStatus;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\AlterEgo;
use App\Models\Pattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\Token;
use App\Services\TargetSearch;
use App\Services\WordCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TargetSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    public function test_search_with_invalid_name_throws_422(): void
    {
        $this->expectException(HttpException::class);
        try {
            app(TargetSearch::class)->search('!!!');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_search_deduplicates_name_variants(): void
    {
        Config::set('search.queue', 'test');
        Bus::fake();

        $search = app(TargetSearch::class);
        $search->search('David McNair');
        $search->search('davidmcnair');
        $search->search('David mcnair');

        $this->assertSame(1, Target::count());
        $this->assertSame('david mcnair', Target::first()->normalized_key);
    }

    public function test_unsatisfiable_patterns_are_not_inserted_as_pending(): void
    {
        Config::set('search.queue', 'test');
        Bus::fake();

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
        Pattern::create([
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

        app(WordCatalog::class)->add('forename', 'jane', 'ok');

        $target = app(TargetSearch::class)->search('Jane');

        $pending = TargetPattern::where('target_id', $target->id)
            ->where('status', TargetPatternStatus::PENDING)
            ->get();

        $this->assertCount(1, $pending);
        $this->assertSame($pForenameOnly->id, $pending->first()->pattern_id);
        Bus::assertDispatched(FillPatternSignaturesJob::class, 1);
    }

    public function test_search_produces_alter_egos_for_an_exact_cover(): void
    {
        Config::set('search.queue', null);
        Config::set('queue.default', 'sync');

        Pattern::create([
            'template' => '{forename}{surname}',
            'popularity_rank' => 1,
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

        $words = app(WordCatalog::class);
        $words->add('forename', 'jane', 'ok');
        $words->add('surname', 'ray', 'fun');

        $target = app(TargetSearch::class)->search('Jane Ray');

        $this->assertSame(['Jane Ray'], AlterEgo::pluck('phrase')->all());
        $this->assertTrue((bool) AlterEgo::first()->isFun);
        $this->assertSame(TargetPatternStatus::FILLED, $target->patterns()->first()->status);
    }
}
