<?php

namespace App\Traits;

/**
 * ScalesJobs provides a helper to dispatch jobs either synchronously (when no
 * queue is configured) or to a configured queue channel. This mirrors the
 * pattern previously implemented inline in services.
 */
trait ScalesJobs
{
    /**
     * Dispatch the given job class with provided arguments, scaling to sync
     * execution if no queue is configured via config('search.queue').
     *
     * @param class-string $jobClass Fully-qualified job class name that supports
     *                               static dispatch()/dispatchSync() methods.
     * @param mixed ...$args Arguments passed to the job's constructor.
     * @return mixed The PendingDispatch (when queued) or the result of dispatchSync.
     */
    protected function scaledDispatch(string $jobClass, ...$args)
    {
        $queue = config('search.queue');
        if (empty($queue)) {
            // Run inline to ensure progress without a queue worker
            return $jobClass::dispatchSync(...$args);
        }
        // Dispatch to the configured queue
        $pending = $jobClass::dispatch(...$args);
        return $pending->onQueue($queue);
    }
}
