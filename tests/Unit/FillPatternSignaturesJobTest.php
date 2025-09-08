<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Models\Word;
use App\Services\SignatureFillService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillPatternSignaturesJobTest extends TestCase
{
    use RefreshDatabase;

    private function addWord(string $word, string $token, string $list, string $signature): Word
    {
        return Word::create([
            'word' => $word,
            'token_type' => $token,
            'list_type' => $list,
            'use_for_search' => true,
            'signature' => $signature,
        ]);
    }

    private function makeSignature(string $s): string
    {
        $norm = strtolower(preg_replace('/[^a-z]/i', '', $s) ?? '');
        $chars = str_split($norm); sort($chars); return implode('', $chars);
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
        $snp = SourceNamePattern::create([
            'source_name_id' => $source->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // Provide words whose signatures are subsets of the source signature
        // forename: jane (aajen -> after normalization 'aejn')
        $this->addWord('jane', 'forename', 'fun', $this->makeSignature('jane'));
        // surname: mary
        $this->addWord('mary', 'surname', 'fun', $this->makeSignature('mary'));

        // Act
        $job = new FillPatternSignaturesJob($snp->id);
        $job->handle(app(WordMatchService::class), app(SignatureFillService::class));

        // Assert: at least one signature-indexed pattern row was created for this SNP
        $count = SignatureIndexedPattern::where('source_name_pattern_id', $snp->id)->count();
        $this->assertGreaterThan(0, $count, 'Expected at least one signature-indexed pattern to be created');

        // Validate that rows use the correct column name 'pattern'
        $row = SignatureIndexedPattern::where('source_name_pattern_id', $snp->id)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('', (string)$row->pattern);
    }
}
