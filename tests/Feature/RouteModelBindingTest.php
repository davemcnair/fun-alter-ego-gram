<?php

namespace Tests\Feature;

use App\Models\SourceName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RouteModelBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_uses_route_model_binding_for_source_name(): void
    {
        // Create two distinct sources to ensure the bound model matches the id in URL and not something else
        $s1 = SourceName::create(['name' => 'Alpha', 'signature' => 'aahlp', 'status' => 'idle']);
        $s2 = SourceName::create(['name' => 'Beta', 'signature' => 'abet', 'status' => 'idle']);

        // Call the named route using the model instance to generate the URL (implicitly uses key)
        $url = route('source-names.start', $s1);
        $res = $this->postJson($url);
        $res->assertOk();

        // After calling start, the bound model (s1) should have been updated to running, while s2 remains idle
        $this->assertSame('running', $s1->fresh()->status, 'Route model binding should resolve {source_name} to the correct model instance');
        $this->assertSame('idle', $s2->fresh()->status, 'Other models should be unaffected');
    }

    public function test_start_404s_for_unknown_source_name_id(): void
    {
        $nonExistentId = 999999;
        // Hitting the URL directly with a non-existent id should result in a 404 by implicit binding
        $res = $this->postJson('/source-names/' . $nonExistentId . '/start');
        $res->assertStatus(404);
    }
}
