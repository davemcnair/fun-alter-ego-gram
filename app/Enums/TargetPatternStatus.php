<?php

namespace App\Enums;

enum TargetPatternStatus
{
    // ready to be filled
    case pending;
    case deferred;
    case processing;
    case filled;
}
