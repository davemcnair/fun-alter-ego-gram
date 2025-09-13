<?php

namespace App\Enums;

enum TargetNameStatus
{
    case idle;
    case processing;
    case processed;
    case error;
}
