<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TokenWordAdded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tokenSignatureWordId;

    public function __construct(int $tokenSignatureWordId)
    {
        $this->tokenSignatureWordId = $tokenSignatureWordId;
    }
}
