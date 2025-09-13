<?php

namespace App\Enums;

enum TargetStatus
{
    case idle;
    case processing;
    case processed;
    case error;
}
