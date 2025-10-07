<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ScalesJobs provides a helper to dispatch jobs either synchronously (when no
 * queue is configured) or to a configured queue channel. This mirrors the
 * pattern previously implemented inline in services.
 */
trait ScalesJobs
{
    /**
     * Dispatch the given job class with provided arguments, scaling to sync
     * execution if no queue is configured via config('search.queue') or when the
     * default queue driver is "sync".
     *
     * @param class-string $jobClass Fully-qualified job class name that supports
     *                               static dispatch()/dispatchSync() methods.
     * @param mixed ...$args Arguments passed to the job's constructor.
     * @return mixed The PendingDispatch (when queued) or the result of dispatchSync.
     */
    protected function scaledDispatch(string $jobClass, ...$args)
    {
        $queue = config('search.queue');
        $driver = config('queue.default');

        // If no explicit queue is configured, or the driver is sync, run inline
        if (empty($queue) || $driver === 'sync') {
            try {
                Log::info('scaled_dispatch', [
                    'job' => $jobClass,
                    'mode' => 'sync',
                ]);
            } catch (Throwable $e) { /* ignore */ }
            return $jobClass::dispatchSync(...$args);
        }

        // Dispatch to the configured queue
        try {
            Log::info('scaled_dispatch', [
                'job' => $jobClass,
                'mode' => 'queue',
                'queue' => $queue,
                'driver' => $driver,
            ]);
        } catch (Throwable $e) { /* ignore */ }

        return $jobClass::dispatch(...$args)->onQueue($queue);
    }
}
