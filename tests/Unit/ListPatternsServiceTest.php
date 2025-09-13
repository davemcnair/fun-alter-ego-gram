<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Token;
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

        // Seed minimal tokens used by filter tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 2],
        ]);

        // Seed a handful of patterns with varying ranks, templates, and mins for list()/listWithinMinLength() tests
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
                'template' => 'cool-{forename}-foo', // will be matched by like '%foo%'
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
        // Like filter should only match the template containing 'foo'
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

    public function test_listWithinMinLength_returns_patterns_sorted_by_rank(): void
    {
        $within = $this->svc->listWithinMinLength(6);

        // Should include patterns with min_total_length <= 6: ranks 1, 2, and 4
        $this->assertCount(3, $within);
        $this->assertSame([1, 2, 4], $within->pluck('popularity_rank')->all());

        // Tighten threshold to 4 => ranks 1 and 4
        $within4 = $this->svc->listWithinMinLength(4);
        $this->assertCount(2, $within4);
        $this->assertSame([1, 4], $within4->pluck('popularity_rank')->all());

        // Tightest threshold to 2 => none
        $within2 = $this->svc->listWithinMinLength(2);
        $this->assertCount(0, $within2);
    }

    public function test_filterPatternsForTarget_rejects_when_required_token_has_no_matches(): void
    {
        // Create dedicated patterns just for this test
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
            'template' => '{forename}{surname}-t1',
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

        $patterns = collect([$pForenameOnly, $pBoth]);

        // Build stored/matching min arrays
        $forenameId = (int)Token::where('name', 'forename')->first()->id;
        $surnameId = (int)Token::where('name', 'surname')->first()->id;
        $stored = [
            $forenameId => 2,
            $surnameId => 2,
        ];
        $matching = [
            $forenameId => 3,
            // deliberately omit surname match to force rejection for patterns requiring surname
        ];

        $targetSig = 'aejn'; // makeSignature('Jane')

        $kept = $this->svc->filterPatternsForTarget($targetSig, $patterns, $stored, $matching);

        $this->assertTrue($kept->contains('id', $pForenameOnly->id), 'Forename-only pattern should be kept when forename matched');
        $this->assertFalse($kept->contains('id', $pBoth->id), 'Pattern requiring surname must be rejected if surname has no matches');
    }

    public function test_filterPatternsForTarget_respects_minimums_and_multiplicity(): void
    {
        // Create a pattern with surname appearing twice
        $pMulti = Pattern::create([
            'template' => '{forename}{surname}{surname}',
            'popularity_rank' => 12,
            'pattern_type' => 'standard',
            'min_total_length' => 6,
            'forename_count' => 1,
            'surname_count' => 2,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);

        $patterns = collect([$pMulti]);

        $forenameId = (int)Token::where('name', 'forename')->first()->id;
        $surnameId = (int)Token::where('name', 'surname')->first()->id;

        // Stored mins (from tokens table) and matching mins
        $stored = [ $forenameId => 2, $surnameId => 2 ];
        $matching = [ $forenameId => 3, $surnameId => 2 ];

        // Target signature length must be >= effective sum: max(2,3)*1 + max(2,2)*2 = 3 + 4 = 7
        $targetSig = 'aaejnry'; // makeSignature('Jane Ray')

        $kept = $this->svc->filterPatternsForTarget($targetSig, $patterns, $stored, $matching);
        $this->assertTrue($kept->contains('id', $pMulti->id), 'Pattern should be accepted when effective minima equal target length');

        // Now shorten target to force rejection (length 6)
        $shortSig = 'aejnry'; // makeSignature('Jean Ry')
        $rejected = $this->svc->filterPatternsForTarget($shortSig, $patterns, $stored, $matching);
        $this->assertFalse($rejected->contains('id', $pMulti->id), 'Pattern should be rejected when effective minima exceed target length');
    }
}
