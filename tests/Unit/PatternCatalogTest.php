<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Token;
use App\Services\PatternCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function rank(string $template): int
    {
        $pattern = Pattern::query()->where('template', $template)->first();
        $this->assertNotNull($pattern, "Expected generated pattern {$template}");

        return (int) $pattern->popularity_rank;
    }
}
