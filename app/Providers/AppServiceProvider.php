<?php

namespace App\Providers;

use App\Events\TokenWordAdded;
use App\Jobs\BackfillNewWordMatchesJob;
use App\Traits\ScalesJobs;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    use ScalesJobs;
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
                // Use scaledDispatch from ScalesJobs trait to honor queue configuration
                $this->scaledDispatch(BackfillNewWordMatchesJob::class, (int)$event->tokenSignatureWordId);
            });
        } catch (Throwable $e) {
            // In some CLI/test contexts Event may not be bound; ignore
        }
    }
}
