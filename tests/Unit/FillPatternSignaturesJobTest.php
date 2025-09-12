<?php

namespace Tests\Unit;

use App\Jobs\ExpandSignatureIndexedPatternsJob;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Services\SignatureFillService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FillPatternSignaturesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed required tokens used by Pattern::parsePatternTokenSlotPositions
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
        ]);
    }

    private function addTSW(string $tokenName, string $word, string $listType = 'fun'): TokenSignatureWord
    {
        $tok = Token::where('name', $tokenName)->firstOrFail();
        $sig = (new class { use \App\Traits\HelpsMatchWords; })->makeSignature($word);
        $ts = TokenSignature::firstOrCreate(['token_id' => $tok->id, 'signature' => $sig]);
        return TokenSignatureWord::create([
            'token_signature_id' => $ts->id,
            'word' => strtolower($word),
            'list_type' => $listType,
            'is_deferred' => false,
        ]);
    }

    public function test_creates_signature_indexed_patterns_for_simple_template(): void
    {
        // Arrange a source and one pattern
        $source = SourceName::create([
            'name' => 'Adam Vinci',
            'signature' => 'aadmciinv',
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $snp = SourceNamePattern::create([
            'source_name_id' => $source->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // Matching words (via signature tables)
        $this->addTSW('forename', 'adam', 'fun');   // 'aadm'
        $this->addTSW('surname', 'vinci', 'ok');    // 'ciinv'

        // Act: run fill job
        $job = new FillPatternSignaturesJob($snp->id);
        // Silence logs during test
        Log::spy();
        $job->handle(app(WordMatchService::class), app(SignatureFillService::class));

        // Assert: a signature-indexed pattern was created
        $rows = SignatureIndexedPattern::where('source_name_pattern_id', $snp->id)->get();
        $this->assertGreaterThanOrEqual(1, $rows->count(), 'Should create at least one signature-indexed pattern');
        $this->assertMatchesRegularExpression('/\{[0-9]+:[a-z]+\}\{[0-9]+:[a-z]+\}/', $rows->first()->pattern);

        // Run expansion to complete lifecycle (optional):
        $exp = new ExpandSignatureIndexedPatternsJob($snp->id);
        $exp->handle(app(\App\Services\PhraseBuilderService::class));
        $this->assertSame('done', $snp->fresh()->status, 'SNP should be marked done by expansion job');
    }

    public function test_no_matches_creates_no_rows_and_does_not_error(): void
    {
        $source = SourceName::create([
            'name' => 'Zzz',
            'signature' => 'zzz',
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $snp = SourceNamePattern::create([
            'source_name_id' => $source->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // No matching TokenSignatureWords inserted
        $job = new FillPatternSignaturesJob($snp->id);
        Log::spy();
        $job->handle(app(WordMatchService::class), app(SignatureFillService::class));

        $rows = SignatureIndexedPattern::where('source_name_pattern_id', $snp->id)->count();
        $this->assertSame(0, $rows, 'Should not create any rows when there are no matches');
    }
}
