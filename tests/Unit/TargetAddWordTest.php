<?php

namespace Tests\Unit;

use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetAddWordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed minimal tokens used throughout tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    private function makeTarget(string $name = 'Jane Doe', string $signature = 'adeejno'): Target
    {
        return Target::create([
            'name' => $name,
            'signature' => $signature,
            'normalized_key' => strtolower(str_replace(' ', '', $name)),
            'status' => 'running',
        ]);
    }

    private function attachSimplePattern(Target $target): TargetPattern
    {
        $p = Pattern::create([
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
        return TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $p->id,
            'popularity_rank' => $p->popularity_rank,
            'status' => 'pending',
        ]);
    }

    public function test_addWord_happy_path_creates_links_enqueues_and_returns_payload(): void
    {
        Config::set('search.queue', 'test');
        Bus::fake();

        $target = $this->makeTarget('Jane', 'aejn'); // signature for 'jane'
        $this->attachSimplePattern($target);

        $res = $this->postJson(route('targets.add-word', $target), [
            'token_type' => 'forename',
            'word' => 'jane',
            'list_type' => 'ok',
        ]);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertTrue($json['ok'] ?? false, 'ok should be true');
        $this->assertArrayHasKey('item', $json);
        // Ensure link exists and has timestamps
        $row = DB::table('target_token_signature_words')->where('target_id', $target->id)->first();
        $this->assertNotNull($row, 'pivot link should exist');
        $this->assertNotNull($row->created_at ?? null, 'pivot should have created_at');

        // Assert a fill job was dispatched for the target pattern
        Bus::assertDispatched(FillPatternSignaturesJob::class, 1);
    }

    public function test_addWord_invalid_list_type_returns_422(): void
    {
        $target = $this->makeTarget('Jane', 'aejn');
        $res = $this->postJson(route('targets.add-word', $target), [
            'token_type' => 'forename',
            'word' => 'jane',
            'list_type' => 'nope',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('ok'));
    }

    public function test_addWord_empty_signature_returns_422(): void
    {
        $target = $this->makeTarget('???', '');
        $res = $this->postJson(route('targets.add-word', $target), [
            'token_type' => 'forename',
            'word' => 'jane',
            'list_type' => 'ok',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('ok'));
    }

    public function test_addWord_idempotent_linking_no_duplicates(): void
    {
        $target = $this->makeTarget('Jane', 'aejn');

        $payload = [ 'token_type' => 'forename', 'word' => 'jane', 'list_type' => 'ok' ];
        $this->postJson(route('targets.add-word', $target), $payload)->assertStatus(200);
        $this->postJson(route('targets.add-word', $target), $payload)->assertStatus(200);

        $count = DB::table('target_token_signature_words')->where('target_id', $target->id)->count();
        $this->assertSame(1, $count, 'should only have one pivot row for the match');
    }

    public function test_addWord_idempotent_no_duplicate_and_enqueues(): void
    {
        Config::set('search.queue', 'test');
        Bus::fake();

        $target = $this->makeTarget('Jane', 'aejn');
        $this->attachSimplePattern($target);

        // First call should insert the link and enqueue
        $this->postJson(route('targets.add-word', $target), [
            'token_type' => 'forename',
            'word' => 'jane',
            'list_type' => 'ok',
        ])->assertStatus(200);

        // Second call should not create duplicates and still enqueue safely
        $this->postJson(route('targets.add-word', $target), [
            'token_type' => 'forename',
            'word' => 'jane',
            'list_type' => 'ok',
        ])->assertStatus(200);

        $count = DB::table('target_token_signature_words')->where('target_id', $target->id)->count();
        $this->assertSame(1, $count);

        Bus::assertDispatched(FillPatternSignaturesJob::class); // at least once
    }
}
