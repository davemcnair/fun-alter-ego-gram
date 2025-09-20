<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SystemController extends Controller
{
    public function heartbeat(Request $request)
    {
        $last = (int) (Cache::get('queue:worker:last_seen') ?? 0);
        $now = time();
        $age = $last ? ($now - $last) : null;
        $fresh = $age !== null && $age < (int) config('queue.heartbeat_fresh_seconds', 15);
        return response()->json([
            'ok' => true,
            'fresh' => $fresh,
            'age' => $age,
            'now' => $now,
            'last_seen' => $last,
        ]);
    }
}
