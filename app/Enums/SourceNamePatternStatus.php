<?php

namespace App\Enums;

enum SourceNamePatternStatus
{
    case pending;
    case processing;
    case done;
}
