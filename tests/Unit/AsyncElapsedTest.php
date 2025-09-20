<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Services\ExpandSignatureIndexedPatternService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsyncElapsedTest extends TestCase
{
    use RefreshDatabase;

    public function test_expand_sets_finished_and_elapsed_ms_for_async(): void
    {
        // Seed minimal signature, target, pattern, and target_pattern with no signatureIndexedPatterns
        $sig = Signature::query()->create(['signature' => 'abcd', 'length' => 4]);
        $target = Target::query()->create([
            'name' => 'ABCD',
            'signature_id' => $sig->id,
            'normalized_key' => 'abcd',
            'status' => 'running',
        ]);
        $pattern = Pattern::query()->create([
            'template' => '{forename}{surname}',
            'popularity_rank' => 1,
            'pattern_type' => 'standard',
        ]);
        $tp = TargetPattern::query()->create([
            'target_id' => $target->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'processing',
            'started_at' => now()->subMilliseconds(50),
        ]);

        // Invoke the expand service directly (no queued data present)
        app(ExpandSignatureIndexedPatternService::class)
            ->expandWithBuilder($tp->id, app(\App\Services\PhraseBuilderService::class));

        $fresh = $tp->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNotNull($fresh->elapsed_ms);
        $this->assertIsInt($fresh->elapsed_ms);
        $this->assertGreaterThanOrEqual(1, $fresh->elapsed_ms);
        $this->assertLessThanOrEqual(3600000, $fresh->elapsed_ms);
    }
}
