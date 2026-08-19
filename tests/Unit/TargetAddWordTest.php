<?php

namespace Tests\Unit;

use App\Dtos\SignatureDto;
use App\Enums\TargetStatus;
use App\Jobs\FillPatternSignaturesJob;
use App\Models\Pattern;
use App\Models\Signature;
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
        $dto = SignatureDto::fromWord($signature);
        $sig = Signature::firstOrCreate(['signature' => $dto->signature], $dto->defaults);
        return Target::create([
            'name' => $name,
            'signature_id' => $sig->id,
            'normalized_key' => strtolower(str_replace(' ', '', $name)),
            'status' => TargetStatus::filterable,
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
        $this->assertArrayHasKey('progress', $json);
        $this->assertArrayHasKey('matchedWords', $json['progress']);
        $this->assertArrayHasKey('hasUncommitted', $json['progress']);
        // Ensure link exists and has timestamps
        $row = DB::table('target_token_signature_words')->where('target_id', $target->id)->first();
        $this->assertNotNull($row, 'pivot link should exist');
        $this->assertNotNull($row->created_at ?? null, 'pivot should have created_at');

        // Assert a fill job was dispatched for the target pattern
        Bus::assertDispatchedTimes(FillPatternSignaturesJob::class, 1);
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


    public function test_addWord_idempotent_linking_no_duplicates(): void
    {
        Config::set('search.queue', 'test'); // isolate queue behavior
        Config::set('search.enable_match_cache', false); // avoid cache-induced flakiness
        Bus::fake();

        $target = $this->makeTarget('Jane', 'aejn');

        $payload = [ 'token_type' => 'forename', 'word' => 'jane', 'list_type' => 'ok' ];
        $res1 = $this->postJson(route('targets.add-word', $target), $payload);
        $res1->assertStatus(200);
        $this->assertTrue(($res1->json('ok') ?? false), 'first call should return ok=true');

        $res2 = $this->postJson(route('targets.add-word', $target), $payload);
        $res2->assertStatus(200);
        $this->assertTrue(($res2->json('ok') ?? false), 'second call should return ok=true');

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
