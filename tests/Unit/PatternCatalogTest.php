<?php

namespace Tests\Unit;

use App\Dtos\PatternCatalogQuery;
use App\Dtos\PatternCatalogRow;
use App\Models\Pattern;
use App\Models\Token;
use App\Services\PatternCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PatternCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'title', 'prio' => 0, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => false, 'has_boring' => false, 'max_multiples' => 1],
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 2],
            ['name' => 'initials', 'prio' => 2, 'min_length' => 1, 'allow_nearly' => false, 'has_fun' => false, 'has_boring' => false, 'max_multiples' => 1],
            ['name' => 'prefix', 'prio' => 3, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => false, 'has_boring' => false, 'max_multiples' => 1],
            ['name' => 'surname', 'prio' => 4, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 5],
            ['name' => 'suffix', 'prio' => 5, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => false, 'has_boring' => false, 'max_multiples' => 1],
            ['name' => 'honorific', 'prio' => 6, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => false, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    public function test_generate_persists_lowest_ranks_for_baseline_templates(): void
    {
        $result = app(PatternCatalog::class)->generate();

        $this->assertGreaterThan(3, $result['stored'] ?? 0);

        $this->assertSame(1, $this->rank('{forename}{surname}'));
        $this->assertSame(2, $this->rank('{forename}{surname:2}'));
        $this->assertSame(3, $this->rank('{title}{forename}{surname}'));
    }

    public function test_generate_does_not_set_pattern_type(): void
    {
        app(PatternCatalog::class)->generate();

        $this->assertTrue(
            Pattern::query()->where('template', '{forename}{surname}')->where('pattern_type', 'exotic')->exists()
        );
        $this->assertSame(0, Pattern::query()->where('pattern_type', '!=', 'exotic')->count());
    }

    public function test_two_forenames_rank_below_one(): void
    {
        app(PatternCatalog::class)->generate();

        $this->assertTrue($this->rank('{forename}{surname}') < $this->rank('{forename:2}{surname}'));
        $this->assertTrue($this->rank('{title}{surname}') < $this->rank('{forename:2}{surname}'));
        $this->assertTrue($this->rank('{forename}{surname:2}') < $this->rank('{forename:2}{surname:2}'));
        $this->assertTrue($this->rank('{title}{surname:2}') < $this->rank('{forename:2}{surname:2}'));
    }

    public function test_three_or_more_surnames_rank_below_two(): void
    {
        app(PatternCatalog::class)->generate();

        $this->assertTrue($this->rank('{forename}{surname:2}') < $this->rank('{forename}{surname:3}'));
        $this->assertTrue($this->rank('{forename}{surname:2}') < $this->rank('{forename}{surname:4}'));
        $this->assertTrue($this->rank('{forename}{surname:2}') < $this->rank('{forename}{surname:5}'));
        $this->assertTrue($this->rank('{forename:2}{surname:2}') < $this->rank('{forename:2}{surname:3}'));
        $this->assertTrue($this->rank('{title}{surname:2}') < $this->rank('{title}{surname:3}'));
    }

    public function test_reorder_writes_new_popularity_ranks(): void
    {
        app(PatternCatalog::class)->generate();

        $first = Pattern::query()->where('template', '{forename}{surname}')->firstOrFail();
        $second = Pattern::query()->where('template', '{forename}{surname:2}')->firstOrFail();
        $this->assertSame(1, (int) $first->popularity_rank);
        $this->assertSame(2, (int) $second->popularity_rank);

        app(PatternCatalog::class)->reorder([$second->id, $first->id]);

        $this->assertSame(1, $this->rank('{forename}{surname:2}'));
        $this->assertSame(2, $this->rank('{forename}{surname}'));
    }

    public function test_export_writes_ordered_json(): void
    {
        app(PatternCatalog::class)->generate();
        $file = resource_path('patterns'.DIRECTORY_SEPARATOR.'patterns.json');
        $previous = is_file($file) ? file_get_contents($file) : null;

        try {
            $result = app(PatternCatalog::class)->export();
            $this->assertTrue($result['ok']);
            $this->assertSame($file, $result['file']);
            $this->assertGreaterThan(0, $result['count']);

            $decoded = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($decoded);
            $this->assertSame('{forename}{surname}', $decoded[0]['template']);
            $this->assertSame(1, $decoded[0]['popularity_rank']);
        } finally {
            if ($previous === null) {
                if (is_file($file)) {
                    unlink($file);
                }
            } else {
                file_put_contents($file, $previous);
            }
        }
    }

    public function test_list_returns_snapshot_rows_and_token_filter(): void
    {
        $catalog = app(PatternCatalog::class);
        $catalog->generate();
        $catalog->setType(
            Pattern::query()->where('template', '{forename}{surname}')->firstOrFail(),
            'standard'
        );

        $all = $catalog->list(new PatternCatalogQuery());
        $this->assertArrayHasKey('title', $all->tokenOptions);
        $this->assertGreaterThan(3, $all->items->count());

        $first = $all->items->first();
        $this->assertInstanceOf(PatternCatalogRow::class, $first);
        $this->assertSame(1, $first->rank);
        $this->assertSame('{forename}{surname}', $first->template);
        $this->assertSame('standard', $first->type);
        $this->assertNotSame('', $first->example);
        $this->assertGreaterThan(0, $first->minLength);

        $titles = $catalog->list(new PatternCatalogQuery(token: 'title'));
        $this->assertTrue($titles->items->isNotEmpty());
        foreach ($titles->items as $row) {
            $this->assertStringContainsString('{title}', $row->template);
        }

        $forenames = $catalog->list(new PatternCatalogQuery(token: 'forename'));
        foreach ($forenames->items as $row) {
            $this->assertMatchesRegularExpression('/\{forename(?::\d+)?\}/', $row->template);
        }
    }

    public function test_create_update_delete_and_set_type(): void
    {
        $catalog = app(PatternCatalog::class);
        $created = $catalog->create([
            'template' => '{forename}{suffix}',
            'popularity_rank' => 99,
            'min_total_length' => 4,
            'forename_count' => 1,
            'surname_count' => 0,
            'has_suffix' => true,
        ]);

        $this->assertSame('{forename}{suffix}', $created->template);
        $this->assertTrue((bool) $created->has_suffix);
        $this->assertFalse((bool) $created->has_title);

        $updated = $catalog->update($created, [
            'template' => '{forename}{honorific}',
            'popularity_rank' => 100,
            'min_total_length' => 5,
            'forename_count' => 1,
            'surname_count' => 0,
            'has_honorific' => true,
        ]);
        $this->assertSame('{forename}{honorific}', $updated->template);
        $this->assertTrue((bool) $updated->has_honorific);
        $this->assertFalse((bool) $updated->has_suffix);

        $typed = $catalog->setType($updated, 'longer');
        $this->assertSame('longer', $typed->pattern_type);

        $this->expectException(InvalidArgumentException::class);
        $catalog->setType($typed, 'nope');
    }

    public function test_create_rejects_duplicate_template_and_delete_removes_row(): void
    {
        $catalog = app(PatternCatalog::class);
        $catalog->create([
            'template' => '{title}{surname}',
            'popularity_rank' => 1,
            'min_total_length' => 3,
            'forename_count' => 0,
            'surname_count' => 1,
            'has_title' => true,
        ]);

        try {
            $catalog->create([
                'template' => '{title}{surname}',
                'popularity_rank' => 2,
                'min_total_length' => 3,
                'forename_count' => 0,
                'surname_count' => 1,
                'has_title' => true,
            ]);
            $this->fail('Expected duplicate template to throw');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unique', strtolower($e->getMessage()));
        }

        $pattern = Pattern::query()->where('template', '{title}{surname}')->firstOrFail();
        $catalog->delete($pattern);
        $this->assertNull(Pattern::query()->where('template', '{title}{surname}')->first());
    }

    private function rank(string $template): int
    {
        $pattern = Pattern::query()->where('template', $template)->first();
        $this->assertNotNull($pattern, "Expected generated pattern {$template}");

        return (int) $pattern->popularity_rank;
    }
}
