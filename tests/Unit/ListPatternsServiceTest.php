<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Services\ListPatternsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListPatternsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ListPatternsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ListPatternsService::class);

        Pattern::insert([
            [
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
            ],
            [
                'template' => '{title}{forename}{surname}',
                'popularity_rank' => 2,
                'pattern_type' => 'standard',
                'min_total_length' => 6,
                'forename_count' => 1,
                'surname_count' => 1,
                'has_title' => true,
                'has_initials' => false,
                'has_prefix' => false,
                'has_suffix' => false,
                'has_honorific' => false,
            ],
            [
                'template' => '{forename}{surname}{suffix}',
                'popularity_rank' => 3,
                'pattern_type' => 'standard',
                'min_total_length' => 8,
                'forename_count' => 1,
                'surname_count' => 1,
                'has_title' => false,
                'has_initials' => false,
                'has_prefix' => false,
                'has_suffix' => true,
                'has_honorific' => false,
            ],
            [
                'template' => 'cool-{forename}-foo',
                'popularity_rank' => 4,
                'pattern_type' => 'novelty',
                'min_total_length' => 3,
                'forename_count' => 1,
                'surname_count' => 0,
                'has_title' => false,
                'has_initials' => false,
                'has_prefix' => false,
                'has_suffix' => false,
                'has_honorific' => false,
            ],
        ]);
    }

    public function test_list_paginates_and_filters_like(): void
    {
        $result = $this->svc->list(['like' => 'foo', 'limit' => 2, 'page' => 1]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('rows', $result);

        $meta = $result['meta'];
        $rows = $result['rows'];

        $this->assertSame(1, $meta['total']);
        $this->assertSame(1, $meta['page']);
        $this->assertSame(1, $meta['pages']);
        $this->assertSame(1, $meta['count']);

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['popularity_rank']);
        $this->assertSame('cool-{forename}-foo', $rows[0]['template']);
        $this->assertSame(3, $rows[0]['min']);
    }
}
