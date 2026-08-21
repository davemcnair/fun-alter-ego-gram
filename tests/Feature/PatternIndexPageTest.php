<?php

namespace Tests\Feature;

use App\Models\Pattern;
use App\Models\Token;
use App\Services\PatternCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatternIndexPageTest extends TestCase
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

    public function test_index_renders_snapshot_filter_and_type_update(): void
    {
        $catalog = app(PatternCatalog::class);
        $catalog->generate();
        $pattern = Pattern::query()->where('template', '{forename}{surname}')->firstOrFail();

        $this->get(route('patterns.index'))
            ->assertOk()
            ->assertSee('Patterns')
            ->assertSee('{forename}{surname}')
            ->assertSee('All tokens');

        $this->get(route('patterns.index', ['token' => 'title']))
            ->assertOk()
            ->assertSee('{title}')
            ->assertDontSee('>{forename}{surname}</td>', false);

        $this->postJson(route('api.patterns.update-type', $pattern), ['pattern_type' => 'standard'])
            ->assertOk()
            ->assertJson(['ok' => true, 'pattern_type' => 'standard']);

        $this->assertSame('standard', $pattern->fresh()->pattern_type);
    }
}
