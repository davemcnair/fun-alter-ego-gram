<?php

namespace App\Jobs;

use App\Services\TargetSearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BackfillNewWordMatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tokenSignatureWordId;

    public function __construct(int $tokenSignatureWordId)
    {
        $this->tokenSignatureWordId = $tokenSignatureWordId;
    }

    public function handle(TargetSearch $search): void
    {
        $search->attachWord($this->tokenSignatureWordId);
    }
}
