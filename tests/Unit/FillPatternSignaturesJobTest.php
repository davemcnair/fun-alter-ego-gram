<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Services\SignatureFillService;
use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillPatternSignaturesJobTest extends TestCase
{
    use RefreshDatabase;
    use HelpsMatchWords;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed required tokens used by Pattern::parsePatternTokenSlotPositions
        \App\Models\Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
        ]);
    }

    public function test_creates_signature_indexed_patterns_for_simple_template(): void
    {
        // Arrange a source and one pattern
        $sig = $this->makeSignature('Mary Jane');
        $source = SourceName::create([
            'name' => 'Mary Jane',
            'signature' => $sig,
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template'=>'{forename}{surname}']);
        $sourceNamePattern = SourceNamePattern::create([
            'source_name_id' => $source->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // Provide words whose signatures are subsets of the source signature
        // forename: jane (aajen -> after normalization 'aejn')
        $wordMatchService = app(WordMatchService::class);
        $wordMatchService->addTokenWord('forename', 'jane', 'fun');
        $wordMatchService->addTokenWord('surname', 'mary', 'fun');

        // Act
        $job = new FillPatternSignaturesJob($sourceNamePattern->id);
        $job->handle($wordMatchService, app(SignatureFillService::class));

        // Assert: at least one signature-indexed pattern row was created for this SNP
        $count = SignatureIndexedPattern::where('source_name_pattern_id', $sourceNamePattern->id)->count();
        $this->assertGreaterThan(0, $count, 'Expected at least one signature-indexed pattern to be created');

        // Validate that rows use the correct column name 'pattern'
        $row = SignatureIndexedPattern::where('source_name_pattern_id', $sourceNamePattern->id)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('', (string)$row->pattern);
    }
}
