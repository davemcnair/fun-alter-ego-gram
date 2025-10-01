<?php

namespace App\Enums;

enum TargetPatternStatus : string
{
    // ready to be filled
    case PENDING = 'pending';
    case DEFERRED = 'deferred';
    case PROCESSING = 'processing';
    case FILLED = 'filled';

    public function isLive(): bool
    {
        return in_array($this, [self::PENDING, self::DEFERRED, self::PROCESSING]);
    }

    public function isFilled(): bool
    {
        return $this === self::FILLED;
    }

    public function isDeferred(): bool
    {
        return $this === self::DEFERRED;
    }

}
