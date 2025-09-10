<?php

namespace Tests\Unit;

use App\Models\MatchedWord;
use App\Models\SourceName;
use App\Models\Token;
use App\Models\Word;
use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceStoreAndExtractTest extends TestCase
{
    use RefreshDatabase;
    use HelpsMatchWords;

    private WordMatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WordMatchService::class);
    }

    private function addWord(string $word, string $token, string $list, bool $useForSearch = true): Word
    {
        return Word::create([
            'word' => $word,
            'token_type' => $token,
            'list_type' => $list,
            'use_for_search' => $useForSearch,
            'signature' => $this->makeSignature($word),
        ]);
    }

    private function makeSource(string $name): SourceName
    {
        return SourceName::create([
            'name' => $name,
            'signature' => $this->makeSignature($name),
            'status' => 'idle',
        ]);
    }

    public function test_storeNewMatchingWords_inserts_only_matching_not_already_linked_respects_boring_and_returns_tokens(): void
    {
        $source = $this->makeSource('Mary Jane'); // aaejmnry

        // Matching subset words
        $w1 = $this->addWord('jane', 'forename', 'fun', true);
        $w2 = $this->addWord('ray', 'surname', 'ok', true);
        $w3 = $this->addWord('mary', 'surname', 'boring', true); // boring subset

        // Non-matching or excluded
        $w4 = $this->addWord('zoo', 'noun', 'fun', true); // not subset
        $w5 = $this->addWord('nana', 'forename', 'ok', false); // not use_for_search

        // Pre-link one to ensure de-duplication for this source
        MatchedWord::create(['source_name_id' => $source->id, 'word_id' => $w1->id, 'used' => false]);

        $tokens = $this->svc->storeNewMatchingWords($source, includeBoring: false);

        sort($tokens);
        $this->assertSame(['surname'], $tokens, 'Only surname had new matches when boring excluded (forename was already linked)');

        $rows = MatchedWord::where('source_name_id', $source->id)->pluck('word_id')->all();
        sort($rows);
        $this->assertSame([$w1->id, $w2->id], $rows, 'Should insert only the new matching row (ray) and keep existing (jane); exclude boring and non-subset/use_for_search=0');

        // Now include boring and run again; should add mary, tokens reported should include surname only (already true)
        $tokens2 = $this->svc->storeNewMatchingWords($source, includeBoring: true);
        sort($tokens2);
        $this->assertSame(['surname'], $tokens2);
        $rows2 = MatchedWord::where('source_name_id', $source->id)->pluck('word_id')->all();
        sort($rows2);
        $this->assertSame([$w1->id, $w2->id, $w3->id], $rows2);
    }

    public function test_storeNewMatchingWords_applies_length_guard_against_source_signature(): void
    {
        $source = $this->makeSource('anna'); // aann
        $short = $this->addWord('ana', 'forename', 'ok', true);     // length 3 subset
        $exact = $this->addWord('anna', 'forename', 'ok', true);    // length 4 subset
        $long = $this->addWord('annas', 'forename', 'ok', true);    // length 5, should be excluded by length

        $tokens = $this->svc->storeNewMatchingWords($source, includeBoring: true);
        sort($tokens);
        $this->assertSame(['forename'], $tokens);

        $linked = MatchedWord::where('source_name_id', $source->id)->with('word')->get()->pluck('word.word')->all();
        sort($linked);
        $this->assertSame(['ana', 'anna'], $linked);
        $this->assertNotContains('annas', $linked);
    }

    public function test_extractMatchingTokenWordMinimumLengths_returns_stored_and_matching_based_mins(): void
    {
        $source = $this->makeSource('Mary Jane'); // aaejmnry

        // Tokens table baseline mins
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
            ['name' => 'adjective','prio' => 3, 'min_length' => 3],
        ]);

        // Words and matched words (simulate prior matching)
        $jane = $this->addWord('jane', 'forename', 'fun', true); // len 4
        $ray  = $this->addWord('ray', 'surname', 'ok', true);    // len 3
        $mean = $this->addWord('mean', 'adjective', 'fun', true);// len 4
        $zoo  = $this->addWord('zoo', 'noun', 'fun', true);      // not subset

        MatchedWord::insert([
            ['source_name_id' => $source->id, 'word_id' => $jane->id, 'used' => false],
            ['source_name_id' => $source->id, 'word_id' => $ray->id, 'used' => false],
            ['source_name_id' => $source->id, 'word_id' => $mean->id, 'used' => false],
            ['source_name_id' => $source->id, 'word_id' => $zoo->id, 'used' => false], // will be filtered out by subset
        ]);

        // Load relation as service expects $sourceName->matchedWords with word eager-loaded
        $source = SourceName::find($source->id);
        $source->load('matchedWords.word');

        [$storedMins, $matchingMins] = $this->svc->extractMatchingTokenWordMinimumLengths($source, ['forename','surname','adjective']);

        $expectedStored = ['forename'=>2,'surname'=>2,'adjective'=>3];
        ksort($expectedStored);
        ksort($storedMins);
        $this->assertSame($expectedStored, $storedMins);
        // Effective mins from matching words
        $this->assertSame(4, $matchingMins['forename']); // jane
        $this->assertSame(3, $matchingMins['surname']);  // ray
        $this->assertSame(4, $matchingMins['adjective']); // mean
        $this->assertArrayNotHasKey('noun', $matchingMins);
    }
}
