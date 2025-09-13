<?php

namespace App\Enums;

enum TargetPatternStatus
{
    case pending;
    case processing;
    case done;
}
