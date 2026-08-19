<?php

namespace Tests\Unit;

use App\Enums\TargetStatus;
use App\Models\AlterEgo;
use App\Models\Pattern;
use App\Models\Token;
use App\Services\TargetSearch;
use App\Services\WordCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ExpandSignaturedPatternsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 2],
            ['name' => 'surname', 'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 2],
        ]);
        Config::set('search.queue', null);
        Config::set('queue.default', 'sync');
    }

    public function test_search_persists_title_cased_forename_and_surname(): void
    {
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
        $words->add('forename', 'adam', 'fun');
        $words->add('surname', 'invic', 'fun');

        $target = app(TargetSearch::class)->search('Adam Invic');

        $this->assertSame(['Adam Invic'], AlterEgo::pluck('phrase')->all());
        $this->assertTrue((bool) AlterEgo::first()->isFun);
        $this->assertSame(TargetStatus::processed, $target->fresh()->status);
    }

    public function test_search_persists_hyphenated_double_surname(): void
    {
        Pattern::create([
            'template' => '{surname:2}',
            'popularity_rank' => 1,
            'pattern_type' => 'standard',
            'min_total_length' => 4,
            'forename_count' => 0,
            'surname_count' => 2,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);

        $words = app(WordCatalog::class);
        $words->add('surname', 'ray', 'fun');
        $words->add('surname', 'vinci', 'ok');

        app(TargetSearch::class)->search('Ray Vinci');

        $this->assertEqualsCanonicalizing(['Ray-Vinci', 'Vinci-Ray'], AlterEgo::pluck('phrase')->all());
    }
}
