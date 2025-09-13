<?php

namespace Tests\Unit;

use App\Models\AlterEgo;
use App\Models\TargetSignatureIndexedPattern;
use App\Models\Target;
use App\Models\TargetPattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_relations_fetch_related_alter_egos_via_signature_indexed_patterns(): void
    {
        // Arrange: two targets, each with a pattern
        $targetA = Target::create([
            'name' => 'Alpha User',
            'signature' => 'aaehlprsuu',
            'status' => 'running',
        ]);
        $targetB = Target::create([
            'name' => 'Beta User',
            'signature' => 'abeerstu',
            'status' => 'running',
        ]);

        $pDef = \App\Models\Pattern::create(['template' => '{forename}{surname}']);
        $patternA = TargetPattern::create([
            'target_id' => $targetA->id,
            'pattern_id' => $pDef->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);
        $patternB = TargetPattern::create([
            'target_id' => $targetB->id,
            'pattern_id' => $pDef->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // Signature-indexed patterns under each pattern
        $sipA1 = TargetSignatureIndexedPattern::create([
            'target_pattern_id' => $patternA->id,
            'pattern' => '{forename:aelp}{surname:ahrsu}',
        ]);
        $sipB1 = TargetSignatureIndexedPattern::create([
            'target_pattern_id' => $patternB->id,
            'pattern' => '{forename:aber}{surname:estu}',
        ]);

        // Create AlterEgos linked to SNPs (as produced by expansion job). They are stored with
        // target_id + target_pattern_id in schema. Ensure they map to the right Target.
        AlterEgo::create([
            'signature_indexed_pattern_id' => $sipA1->id,
            'phrase' => 'Plea Ashur',
            'starred' => false,
        ]);
        AlterEgo::create([
            'signature_indexed_pattern_id' => $sipB1->id,
            'phrase' => 'Bear Stue',
            'starred' => true,
        ]);

        // Act: fetch related collections from Target
        $sigPatternsA = $targetA->signatureIndexedPatterns; // via hasManyThrough(TargetPattern)
        $alterEgosA = $targetA->alterEgos; // via hasManyThrough(SignatureIndexedPattern) or direct mapping

        // Assert: signature-indexed patterns only for A
        $this->assertCount(1, $sigPatternsA);
        $this->assertSame($patternA->id, $sigPatternsA->first()->target_name_pattern_id);

        // Assert: alter egos only for A
        $this->assertCount(1, $alterEgosA);
        $this->assertSame('Plea Ashur', $alterEgosA->first()->phrase);

        // Sanity: B has its own and should not leak into A
        $this->assertSame(1, $targetB->signatureIndexedPatterns->count());
        $this->assertSame('Bear Stue', $targetB->alterEgos->first()->phrase);
    }
}
