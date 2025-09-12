<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\SourceNamePattern;
use App\Models\Token;
use App\Services\ListPatternsService;
use App\Services\SourceNameCreationService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App as AppFacade;
use Tests\TestCase;

class SourceNameCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_source_and_links_patterns_and_dispatches_jobs(): void
    {
        // Ensure queue set but we will fake bus so nothing actually runs
        Config::set('search.queue', null);
        Bus::fake();

        // Seed tokens used by filtering logic (ids are used indirectly by WordMatchService in real life)
        Token::insert([
            ['name' => Token::TOKEN_NAME_FORENAME, 'prio' => 1, 'min_length' => 2],
            ['name' => Token::TOKEN_NAME_SURNAME,  'prio' => 2, 'min_length' => 2],
        ]);

        // Create a couple of patterns to be returned by the mocked ListPatternsService
        $p1 = Pattern::create([
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
        $p2 = Pattern::create([
            'template' => '{forename:2}{surname}',
            'popularity_rank' => 2,
            'pattern_type' => 'exotic',
            'min_total_length' => 6,
            'forename_count' => 2,
            'surname_count' => 1,
        ]);

        // Mock dependencies
        $listMock = $this->mock(ListPatternsService::class, function($mock) use ($p1, $p2) {
            $mock->shouldReceive('listWithinMinLength')
                ->andReturn(collect([$p1, $p2]));
            $mock->shouldReceive('filterPatternsForSource')
                ->andReturn(collect([$p1, $p2]));
        });
        $wordMock = $this->mock(WordMatchService::class, function($mock) {
            $mock->shouldReceive('storeNewSourceNameMatchedTokenSignatureWords')
                ->andReturn([1,2]);
            $mock->shouldReceive('extractMatchingTokenWordMinimumLengths')
                ->andReturn([
                    // stored mins by token id (dummy)
                    [1 => 2, 2 => 2],
                    // matched mins by token id (dummy)
                    [1 => 2, 2 => 2],
                ]);
        });

        $svc = AppFacade::make(SourceNameCreationService::class);

        $result = $svc->create('Jane Ray', includeBoring: false);

        $this->assertArrayHasKey('source', $result);
        $source = $result['source'];
        $this->assertEquals(' Jane Ray ', ' '.trim($source->name).' ');
        $this->assertEquals('aaejnry', $source->signature);
        $this->assertEquals('running', $source->status);

        // Ensure patterns were linked with correct statuses
        $links = SourceNamePattern::where('source_name_id', $source->id)->orderBy('pattern_id')->get();
        $this->assertCount(2, $links);
        // p1 is standard => pending; p2 is exotic => deferred
        $this->assertEquals('pending', $links[0]->status);
        $this->assertEquals('deferred', $links[1]->status);

        // Ensure a job was dispatched for the pending link
        Bus::assertDispatched(FillPatternSignaturesJob::class, 1);
    }
}
