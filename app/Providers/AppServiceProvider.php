<?php

namespace App\Providers;

use App\Events\TokenWordAdded;
use App\Jobs\BackfillNewWordMatchesJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Listen for TokenWordAdded events and enqueue backfill job
        try {
            Event::listen(TokenWordAdded::class, function($event){
                $dispatch = BackfillNewWordMatchesJob::dispatch((int)$event->tokenSignatureWordId);
                $queue = config('search.queue');
                if (!empty($queue)) { $dispatch->onQueue($queue); }
            });
        } catch (Throwable $e) {
            // In some CLI/test contexts Event may not be bound; ignore
        }
    }
}
