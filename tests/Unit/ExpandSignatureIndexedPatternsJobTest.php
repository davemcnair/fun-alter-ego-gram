<?php

namespace Tests\Unit;

use App\Jobs\ExpandSignatureIndexedPatternsJob;
use App\Models\AlterEgo;
use App\Models\Pattern;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Services\PhraseBuilderService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpandSignatureIndexedPatternsJobTest extends TestCase
{
    use RefreshDatabase;


    public function test_expands_single_signatureIndexed_pattern_with_fun_preference(): void
    {
        // Arrange: a source with one pattern {forename}{surname}
        $source = SourceName::create([
            'name' => 'Dummy',
            'signature' => 'dmmuy',
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $snp = SourceNamePattern::create([
            'source_name_id' => $source->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);
        // SignatureIndexed fill to expand: Adam + Vinci
        SignatureIndexedPattern::create([
            'source_name_pattern_id' => $snp->id,
            'pattern' => '{1:aadm}{2:ciinv}',
        ]);

        $wordService = app(WordMatchService::class);
        // Words: forename 'Adam' (fun) matches aadm; surname: prefer fun over ok for the same signature
        $wordService->addTokenWord('forename','Adam', 'fun');
        // Only one representative row is allowed per (token_type, signature) due to a partial unique index.
        // So we provide a single FUN representative for the surname signature.
        $wordService->addTokenWord('surname','InVic', 'fun');

        // Act: run job
        $job = new ExpandSignatureIndexedPatternsJob($snp->id);
        $job->handle(app(PhraseBuilderService::class));

        // Assert: an AlterEgo was created with the fun-preferred surname and proper capitalization
        $ae = AlterEgo::where('signature_indexed_pattern_id', $source->id)->first();
        $this->assertNotNull($ae, 'AlterEgo should have been created');
        $this->assertSame($snp->id, $ae->source_name_pattern_id);
        // PhraseBuilderService capitalizes tokens; expect "Adam Invic"
        $this->assertSame('Adam Invic', $ae->phrase);

        // Status should be marked done
        $this->assertSame('done', $snp->fresh()->status);
    }

    public function test_expands_double_surname_hyphenated_and_marks_done(): void
    {
        // Arrange: a pattern with two surnames
        $source = SourceName::create([
            'name' => 'Dummy',
            'signature' => 'dmmuy',
            'status' => 'running',
        ]);
        $pattern2 = Pattern::create(['template' => '{surname:2}']);
        $snp = SourceNamePattern::create([
            'source_name_id' => $source->id,
            'pattern_id' => $pattern2->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);
        SignatureIndexedPattern::create([
            'source_name_pattern_id' => $snp->id,
            'pattern' => '{2:ary}{2:ciinv}',
        ]);


        $wordService = app(WordMatchService::class);

        // Words for the two signatures
        $wordService->addTokenWord('surname','ray', 'fun');
        $wordService->addTokenWord('surname','vinci', 'ok');

        // Act
        $job = new ExpandSignatureIndexedPatternsJob($snp->id);
        $job->handle(app(PhraseBuilderService::class));

        // Assert: hyphenated and capitalized surnames
        $ae = AlterEgo::where('source_name_id', $source->id)->first();
        $this->assertNotNull($ae);
        $this->assertSame('Ray-Vinci', $ae->phrase);
        $this->assertSame('done', $snp->fresh()->status);
    }
}
