<?php

namespace App\Enums;

enum SourceNameStatus
{
    case idle;
    case processing;
    case processed;
    case error;
}
