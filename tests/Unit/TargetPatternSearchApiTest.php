<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetPattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetPatternSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeTarget(string $name = 'Jane Doe', string $sig = 'adeejno'): Target
    {
        $signature = Signature::firstOrCreate(['signature' => $sig], [ 'length' => strlen($sig) ]);
        return Target::create([
            'name' => $name,
            'signature_id' => $signature->id,
            'normalized_key' => strtolower(str_replace(' ', '', $name)),
            'status' => 'running',
        ]);
    }

    private function makePattern(): Pattern
    {
        return Pattern::create([
            'template' => '{forename}',
            'popularity_rank' => 1,
            'pattern_type' => 'standard',
            'min_total_length' => 2,
            'forename_count' => 1,
            'surname_count' => 0,
            'has_title' => false,
            'has_initials' => false,
            'has_prefix' => false,
            'has_suffix' => false,
            'has_honorific' => false,
        ]);
    }

    public function test_search_target_pattern_returns_ok_when_done(): void
    {
        $target = $this->makeTarget('Jane', 'aejn');
        $p = $this->makePattern();
        $tp = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $p->id,
            'popularity_rank' => $p->popularity_rank,
            'status' => 'filled',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'elapsed_ms' => 123,
        ]);

        $res = $this->postJson(route('api.target-patterns.search', ['pattern' => $tp->id]));
        $res->assertStatus(200);
        $json = $res->json();
        $this->assertTrue($json['ok'] ?? false);
        $this->assertSame($tp->id, $json['pattern']['id'] ?? null);
        $this->assertSame('filled', $json['pattern']['status'] ?? null);
        $this->assertArrayHasKey('signatureIndexedPatternsCount', $json['pattern']);
        $this->assertArrayHasKey('alterEgosCount', $json['pattern']);
        $this->assertArrayHasKey('elapsed_ms', $json['pattern']);
    }

    public function test_search_target_pattern_returns_ok_when_processing(): void
    {
        $target = $this->makeTarget('Jane', 'aejn');
        $p = $this->makePattern();
        $tp = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $p->id,
            'popularity_rank' => $p->popularity_rank,
            'status' => 'processing',
            'started_at' => now()->subMinute(),
        ]);

        $res = $this->postJson(route('api.target-patterns.search', ['pattern' => $tp->id]));
        $res->assertStatus(200);
        $json = $res->json();
        $this->assertTrue($json['ok'] ?? false);
        $this->assertSame('processing', $json['pattern']['status'] ?? null);
    }
}
