<?php

namespace App\Enums;

enum TargetStatus
{
    case filterable;
    case fillable;
    case processing;
    case processed;
}
