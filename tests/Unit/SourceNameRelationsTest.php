<?php

namespace Tests\Unit;

use App\Models\AlterEgo;
use App\Models\SignatureIndexedPattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceNameRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_name_relations_fetch_related_alter_egos_via_signature_indexed_patterns(): void
    {
        // Arrange: two sources, each with a pattern
        $sourceA = SourceName::create([
            'name' => 'Alpha User',
            'signature' => 'aaehlprsuu',
            'status' => 'running',
        ]);
        $sourceB = SourceName::create([
            'name' => 'Beta User',
            'signature' => 'abeerstu',
            'status' => 'running',
        ]);

        $pDef = \App\Models\Pattern::create(['template' => '{forename}{surname}']);
        $patternA = SourceNamePattern::create([
            'source_name_id' => $sourceA->id,
            'pattern_id' => $pDef->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);
        $patternB = SourceNamePattern::create([
            'source_name_id' => $sourceB->id,
            'pattern_id' => $pDef->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // Signature-indexed patterns under each pattern
        $sipA1 = SignatureIndexedPattern::create([
            'source_name_pattern_id' => $patternA->id,
            'pattern' => '{forename:aelp}{surname:ahrsu}',
        ]);
        $sipB1 = SignatureIndexedPattern::create([
            'source_name_pattern_id' => $patternB->id,
            'pattern' => '{forename:aber}{surname:estu}',
        ]);

        // Create AlterEgos linked to SNPs (as produced by expansion job). They are stored with
        // source_name_id + source_name_pattern_id in schema. Ensure they map to the right SourceName.
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

        // Act: fetch related collections from SourceName
        $sigPatternsA = $sourceA->signatureIndexedPatterns; // via hasManyThrough(SourceNamePattern)
        $alterEgosA = $sourceA->alterEgos; // via hasManyThrough(SignatureIndexedPattern) or direct mapping

        // Assert: signature-indexed patterns only for A
        $this->assertCount(1, $sigPatternsA);
        $this->assertSame($patternA->id, $sigPatternsA->first()->source_name_pattern_id);

        // Assert: alter egos only for A
        $this->assertCount(1, $alterEgosA);
        $this->assertSame('Plea Ashur', $alterEgosA->first()->phrase);

        // Sanity: B has its own and should not leak into A
        $this->assertSame(1, $sourceB->signatureIndexedPatterns->count());
        $this->assertSame('Bear Stue', $sourceB->alterEgos->first()->phrase);
    }
}
