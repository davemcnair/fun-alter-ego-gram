<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

class QueueHeartbeatServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // When a worker is running, it will call the looping callback frequently
        Queue::looping(function () {
            try {
                Cache::put('queue:worker:last_seen', now()->timestamp, 60);
            } catch (\Throwable $e) {
                Log::debug('queue.heartbeat.cache_failed', ['error' => $e->getMessage()]);
            }
        });
    }
}
