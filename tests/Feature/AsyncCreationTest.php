<?php

namespace Tests\Feature;

use App\Jobs\CreateTargetJob;
use App\Models\Signature;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_dispatches_job_and_redirects(): void
    {
        Queue::fake();
        $res = $this->post(route('targets.store'), [ 'name' => 'Jane Doe' ]);
        $res->assertRedirect();
        Queue::assertPushed(CreateTargetJob::class);
        $this->assertDatabaseHas('targets', [ 'normalized_key' => 'jane doe', 'status' => 'queued' ]);
    }

    public function test_heartbeat_endpoint_reports_freshness(): void
    {
        Cache::put('queue:worker:last_seen', time(), 60);
        $res = $this->get(route('system.heartbeat'));
        $res->assertOk()->assertJsonStructure(['ok','fresh','age','now','last_seen']);
    }

    public function test_progress_endpoint_counts(): void
    {
        $sig = Signature::query()->create(['signature' => 'a1b2', 'length' => 4]);
        $target = Target::query()->create(['name' => 'ABCD', 'signature_id' => $sig->id, 'normalized_key' => 'abcd', 'status' => 'processing']);
        $res = $this->get(route('targets.progress', $target));
        $res->assertOk()->assertJsonStructure(['ok','status','updated_at','patterns'=>['total','completed','running','pending'],'alterEgosCount']);
    }
}
