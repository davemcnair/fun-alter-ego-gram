<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Services\PatternQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatternQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMinimalTokens(): void
    {
        $now = now();
        $rows = [];
        // Provide a reasonable min_length for each token type
        $defaults = [
            Token::TOKEN_NAME_TITLE => 2,
            // Set min lengths to match the actual effective mins found from words for the source
            Token::TOKEN_NAME_FORENAME => 4, // 'John' => 4 letters
            Token::TOKEN_NAME_INITIALS => 1,
            Token::TOKEN_NAME_PREFIX => 1,
            Token::TOKEN_NAME_SURNAME => 3, // 'Doe' => 3 letters
            Token::TOKEN_NAME_SUFFIX => 1,
            Token::TOKEN_NAME_HONORIFIC => 2,
        ];
        $prioMap = [
            Token::TOKEN_NAME_SURNAME => 1,
            Token::TOKEN_NAME_FORENAME => 2,
            Token::TOKEN_NAME_TITLE => 3,
            Token::TOKEN_NAME_HONORIFIC => 4,
            Token::TOKEN_NAME_PREFIX => 5,
            Token::TOKEN_NAME_SUFFIX => 6,
            Token::TOKEN_NAME_INITIALS => 7,
        ];
        foreach (Token::NAMES as $name) {
            $rows[] = [
                'name' => $name,
                'prio' => $prioMap[$name] ?? 999,
                'min_length' => $defaults[$name],
                'allow_nearly' => false,
                'has_fun' => false,
                'has_boring' => false,
                'max_multiples' => $name === Token::TOKEN_NAME_SURNAME ? 5 : ($name === Token::TOKEN_NAME_FORENAME ? 2 : 1),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('tokens')->insert($rows);
    }

    protected function seedInflatedTokens(): void
    {
        $now = now();
        $rows = [];
        // Choose mins to inflate the effective sum: non-zero for all tokens,
        // and forename set smaller than actual matching word to force minLengthUnchanged=false
        $defaults = [
            Token::TOKEN_NAME_TITLE => 2,
            Token::TOKEN_NAME_FORENAME => 3, // will be bumped to 4 by 'John'
            Token::TOKEN_NAME_INITIALS => 2,
            Token::TOKEN_NAME_PREFIX => 2,
            Token::TOKEN_NAME_SURNAME => 3,
            Token::TOKEN_NAME_SUFFIX => 2,
            Token::TOKEN_NAME_HONORIFIC => 2,
        ];
        $prioMap = [
            Token::TOKEN_NAME_SURNAME => 1,
            Token::TOKEN_NAME_FORENAME => 2,
            Token::TOKEN_NAME_TITLE => 3,
            Token::TOKEN_NAME_HONORIFIC => 4,
            Token::TOKEN_NAME_PREFIX => 5,
            Token::TOKEN_NAME_SUFFIX => 6,
            Token::TOKEN_NAME_INITIALS => 7,
        ];
        foreach (Token::NAMES as $name) {
            $rows[] = [
                'name' => $name,
                'prio' => $prioMap[$name] ?? 999,
                'min_length' => $defaults[$name],
                'allow_nearly' => false,
                'has_fun' => false,
                'has_boring' => false,
                'max_multiples' => $name === Token::TOKEN_NAME_SURNAME ? 5 : ($name === Token::TOKEN_NAME_FORENAME ? 2 : 1),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('tokens')->insert($rows);
    }

    protected function seedPatterns(): void
    {
        $now = now();
        DB::table('patterns')->insert([
            [
                'template' => '{forename}{surname}',
                'popularity_rank' => 1,
                'min_total_length' => 4, // based on token mins
                'forename_count' => 1,
                'surname_count' => 1,
                'has_title' => false,
                'has_initials' => false,
                'has_prefix' => false,
                'has_suffix' => false,
                'has_honorific' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template' => '{forename}{surname}{suffix}',
                'popularity_rank' => 2,
                'min_total_length' => 5,
                'forename_count' => 1,
                'surname_count' => 1,
                'has_title' => false,
                'has_initials' => false,
                'has_prefix' => false,
                'has_suffix' => true,
                'has_honorific' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    protected function seedWords(): void
    {
        $now = now();
        // Words that can be made from letters of "John Doe"
        DB::table('words')->insert([
            [
                'word' => 'John',
                'token_type' => Token::TOKEN_NAME_FORENAME,
                'list_type' => 'ok',
                'signature' => 'hjno', // sorted letters of john
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'word' => 'Doe',
                'token_type' => Token::TOKEN_NAME_SURNAME,
                'list_type' => 'ok',
                'signature' => 'deo',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // No suffix words seeded on purpose -> patterns with suffix should be excluded
        ]);
    }

    public function test_list_for_source_includes_valid_and_excludes_unavailable_tokens(): void
    {
        $this->seedMinimalTokens();
        $this->seedPatterns();
        $this->seedWords();

        $svc = new PatternQueryService();
        $res = $svc->listForSource('John Doe');

        // Meta assertions
        $this->assertSame(7, $res['meta']['source_len']); // signature of "johndoe" is length 7
        $this->assertSame('excluded', $res['meta']['boring'] ?? 'excluded');
        $this->assertGreaterThan(0, $res['meta']['count']);

        $templates = array_map(fn($r) => $r['template'], $res['rows']);
        $this->assertContains('{forename}{surname}', $templates, 'Expected {forename}{surname} to be included');
        $this->assertNotContains('{forename}{surname}{suffix}', $templates, 'Patterns requiring suffix should be excluded when no suffix words match');
    }

    public function test_list_for_source_includes_suffix_when_boring_is_included(): void
    {
        $this->seedMinimalTokens();
        $this->seedPatterns();
        $this->seedWords();
        // Add a boring suffix word that fits "John Doe": "O" -> signature "o"
        DB::table('words')->insert([
            [
                'word' => 'O',
                'token_type' => Token::TOKEN_NAME_SUFFIX,
                'list_type' => 'boring',
                'signature' => 'o',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $svc = new PatternQueryService();
        // Default excludes boring
        $resDefault = $svc->listForSource('John Doe');
        $templatesDefault = array_map(fn($r) => $r['template'], $resDefault['rows']);
        $this->assertNotContains('{forename}{surname}{suffix}', $templatesDefault, 'Suffix pattern should still be excluded when boring is excluded by default');

        // Include boring -> suffix pattern should now be included
        $resBoring = $svc->listForSource('John Doe', true);
        $templatesBoring = array_map(fn($r) => $r['template'], $resBoring['rows']);
        $this->assertContains('{forename}{surname}{suffix}', $templatesBoring, 'Suffix pattern should be included when boring words are considered');
    }

    public function test_list_for_source_excludes_when_effective_sum_exceeds_source(): void
    {
        $this->seedInflatedTokens();
        $this->seedPatterns();
        $this->seedWords();

        $svc = new PatternQueryService();
        $res = $svc->listForSource('John Doe');
        $templates = array_map(fn($r) => $r['template'], $res['rows']);

        // Because minLengthUnchanged becomes false (forename 3 -> effective 4) and
        // the sum of effective mins across all tokens (>= 17) exceeds source_len (7),
        // the basic pattern should be excluded.
        $this->assertNotContains('{forename}{surname}', $templates, 'Pattern should be excluded when effective sum exceeds source length');
    }
}
