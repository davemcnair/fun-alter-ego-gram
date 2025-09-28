<?php

namespace Tests\Unit;


use App\Models\AlterEgo;
use App\Models\Pattern;
use App\Models\Signature;
use App\Models\TargetSignatureIndexedPattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\Token;
use App\Services\PhraseBuilderService;
use App\Services\WordMatchService;
use App\Services\ExpandSignatureIndexedPatternService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpandSignatureIndexedPatternsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed required tokens used by Pattern::parsePatternTokenSlotPositions
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname', 'prio' => 2, 'min_length' => 2],
        ]);
    }

    public function test_expands_single_signatureIndexed_pattern_with_fun_preference(): void
    {
        // Arrange: a target with one pattern {forename}{surname}
        $sig = Signature::firstOrCreate(['signature' => 'dmmuy'], [
            'length' => 5,
            'd_count' => 1,
            'm_count' => 2,
            'u_count' => 1,
            'y_count' => 1,
        ]);
        $target = Target::create([
            'name' => 'Dummy',
            'signature_id' => $sig->id,
            'normalized_key' => 'dummy',
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $targetPattern = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);
        // SignatureIndexed fill to expand: Adam + Vinci
        TargetSignatureIndexedPattern::create([
            'target_pattern_id' => $targetPattern->id,
            'pattern' => '{1:aadm}{2:ciinv}',
        ]);

        $wordService = app(WordMatchService::class);
        // Words: forename 'Adam' (fun) matches aadm; surname: prefer fun over ok for the same signature
        $wordService->addTokenWord('forename','Adam', 'fun');
        // Only one representative row is allowed per (token_type, signature) due to a partial unique index.
        // So we provide a single FUN representative for the surname signature.
        $wordService->addTokenWord('surname','InVic', 'fun');

        // Act: run expansion via service
        app(ExpandSignatureIndexedPatternService::class)
            ->expandWithBuilder($targetPattern->id, app(PhraseBuilderService::class));

        // Assert: an AlterEgo was created with the fun-preferred surname and proper capitalization
        /** @var AlterEgo $ae */
        $ae = $targetPattern->alterEgos()->first();
        $this->assertNotNull($ae, 'AlterEgo should have been created');
        // PhraseBuilderService capitalizes tokens; expect "Adam Invic"
        $this->assertSame('Adam Invic', $ae->phrase);

        // Status should be marked done
        $this->assertSame('filled', $targetPattern->fresh()->status);
    }

    public function test_expands_double_surname_hyphenated_and_marks_done(): void
    {
        // Arrange: a pattern with two surnames
        $sig = Signature::firstOrCreate(['signature' => 'dmmuy'], [
            'length' => 5,
            'd_count' => 1,
            'm_count' => 2,
            'u_count' => 1,
            'y_count' => 1,
        ]);
        $target = Target::create([
            'name' => 'Dummy',
            'signature_id' => $sig->id,
            'normalized_key' => 'dummy',
            'status' => 'running',
        ]);
        $pattern2 = Pattern::create(['template' => '{surname:2}']);
        $snp = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $pattern2->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);
        TargetSignatureIndexedPattern::create([
            'target_pattern_id' => $snp->id,
            'pattern' => '{2:ary}{2:ciinv}',
        ]);


        $wordService = app(WordMatchService::class);

        // Words for the two signatures
        $wordService->addTokenWord('surname','ray', 'fun');
        $wordService->addTokenWord('surname','vinci', 'ok');

        // Act via service
        app(ExpandSignatureIndexedPatternService::class)
            ->expandWithBuilder($snp->id, app(PhraseBuilderService::class));

        // Assert: hyphenated and capitalized surnames
        $ae = $snp->alterEgos()->first();
        $this->assertNotNull($ae);
        $this->assertSame('Ray-Vinci', $ae->phrase);
        $this->assertSame('filled', $snp->fresh()->status);
    }
}
