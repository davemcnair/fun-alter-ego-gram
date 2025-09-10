<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Token;
use App\Services\ListPatternsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ListPatternsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ListPatternsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ListPatternsService::class);
    }

    private function addPattern(array $overrides = []): Pattern
    {
        static $i = 1;
        $defaults = [
            'template' => "{forename}{surname}" . ($i++),
            'popularity_rank' => 1000,
            'pattern_type' => 'standard',
            'min_total_length' => 5,
            'forename_count' => 1,
            'surname_count' => 1,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ];
        return Pattern::create(array_merge($defaults, $overrides));
    }

    public function test_list_paginates_and_filters_by_like_and_returns_shape(): void
    {
        // Create patterns with different popularity ranks and templates
        $p1 = $this->addPattern(['template' => '{forename}{surname} alpha', 'popularity_rank' => 2, 'min_total_length' => 7]);
        $p2 = $this->addPattern(['template' => '{forename}{surname} beta',  'popularity_rank' => 1, 'min_total_length' => 6]);
        $p3 = $this->addPattern(['template' => '{forename} of {surname}',   'popularity_rank' => 3, 'min_total_length' => 8]);

        // Like filter to only include templates containing 'surname'
        $result = $this->svc->list(['like' => 'surname', 'limit' => 2, 'page' => 1]);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('rows', $result);

        $meta = $result['meta'];
        $rows = $result['rows'];

        $this->assertSame(3, $meta['total']);
        $this->assertSame(1, $meta['page']);
        $this->assertSame(2, $meta['pages']); // limit 2 -> pages ceil(3/2)=2
        $this->assertSame(2, $meta['count']);

        // Rows should be ordered by popularity_rank ASC and limited to 2
        $this->assertSame($p2->popularity_rank, $rows[0]['popularity_rank']);
        $this->assertSame($p2->template, $rows[0]['template']);
        $this->assertSame($p2->min_total_length, $rows[0]['min']);

        $this->assertSame($p1->popularity_rank, $rows[1]['popularity_rank']);
        $this->assertSame($p1->template, $rows[1]['template']);
        $this->assertSame($p1->min_total_length, $rows[1]['min']);

        // Page 2 should contain the remaining row
        $result2 = $this->svc->list(['like' => 'surname', 'limit' => 2, 'page' => 2]);
        $this->assertSame(1, $result2['meta']['count']);
        $this->assertSame($p3->template, $result2['rows'][0]['template']);
    }

    public function test_listWithinMinLength_returns_only_patterns_with_min_leq_threshold_ordered(): void
    {
        $p1 = $this->addPattern(['min_total_length' => 5, 'popularity_rank' => 3]);
        $p2 = $this->addPattern(['min_total_length' => 7, 'popularity_rank' => 1]);
        $p3 = $this->addPattern(['min_total_length' => 9, 'popularity_rank' => 2]);

        $rows = $this->svc->listWithinMinLength(7);
        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(2, $rows);
        // Should be ordered by popularity_rank ASC
        $this->assertSame([$p2->id, $p1->id], $rows->pluck('id')->all());
    }

    public function test_filterPatternsForSource_accepts_when_min_lengths_unchanged_even_if_exceeds_source(): void
    {
        // Pattern requires forename and surname; stored mins equal matching mins
        $pat = $this->addPattern(['forename_count' => 1, 'surname_count' => 1]);
        $patterns = collect([$pat]);

        $stored = ['forename' => 4, 'surname' => 4];
        $matching = ['forename' => 4, 'surname' => 4]; // unchanged
        $sourceSig = str_repeat('a', 7); // length 7

        $filtered = $this->svc->filterPatternsForSource($sourceSig, $patterns, $stored, $matching);
        $this->assertCount(1, $filtered, 'Unchanged mins should pass even if 4+4=8 > 7');
    }

    public function test_filterPatternsForSource_rejects_when_required_token_has_no_words(): void
    {
        $pat = $this->addPattern(['forename_count' => 1, 'surname_count' => 0]);
        $patterns = collect([$pat]);
        $stored = ['forename' => 2];
        $matching = []; // no words found for forename
        $sourceSig = 'aaaa';

        $filtered = $this->svc->filterPatternsForSource($sourceSig, $patterns, $stored, $matching);
        $this->assertCount(0, $filtered, 'Required token without any matching words should be rejected');
    }

    public function test_filterPatternsForSource_uses_dynamic_min_with_counts_and_source_length_gate(): void
    {
        // Pattern with 2 forenames and 1 surname
        $pat = $this->addPattern(['forename_count' => 2, 'surname_count' => 1]);
        $patterns = collect([$pat]);

        // Stored mins lower than matching mins -> considered increased
        $stored = ['forename' => 2, 'surname' => 2];
        $matching = ['forename' => 3, 'surname' => 4];

        // dynamicMin = forename(2 slots)*3 + surname(1)*4 = 10
        $sourceSigShort = str_repeat('a', 9);
        $sourceSigLong = str_repeat('a', 10);

        $filteredShort = $this->svc->filterPatternsForSource($sourceSigShort, $patterns, $stored, $matching);
        $this->assertCount(0, $filteredShort, 'Increased mins with sum>source length should be rejected');

        $filteredLong = $this->svc->filterPatternsForSource($sourceSigLong, $patterns, $stored, $matching);
        $this->assertCount(1, $filteredLong, 'Should pass when dynamic min equals source length');
    }

    public function test_filterPatternsForSource_ignores_tokens_not_used_by_pattern_when_summing_dynamic_min(): void
    {
        // Pattern only uses surname
        $pat = $this->addPattern(['forename_count' => 0, 'surname_count' => 1]);
        $patterns = collect([$pat]);

        $stored = ['surname' => 3, 'forename' => 2];
        $matching = ['surname' => 5, 'forename' => 100]; // forename not used; should not affect sum

        // dynamicMin should be 5; with source length 5 passes; with 4 fails
        $okSig = str_repeat('a', 5);
        $badSig = str_repeat('a', 4);

        $ok = $this->svc->filterPatternsForSource($okSig, $patterns, $stored, $matching);
        $bad = $this->svc->filterPatternsForSource($badSig, $patterns, $stored, $matching);

        $this->assertCount(1, $ok);
        $this->assertCount(0, $bad);
    }

    public function test_filterPatternsForSource_accepts_pattern_with_no_tokens(): void
    {
        $pat = $this->addPattern([
            'forename_count' => 0,
            'surname_count' => 0,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);
        $patterns = collect([$pat]);
        $stored = [];
        $matching = [];
        $sourceSig = 'aaaa';

        $filtered = $this->svc->filterPatternsForSource($sourceSig, $patterns, $stored, $matching);
        $this->assertCount(1, $filtered, 'Pattern with no tokens should pass');
    }
}
