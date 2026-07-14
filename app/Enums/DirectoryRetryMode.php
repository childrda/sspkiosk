<?php

namespace App\Enums;

enum DirectoryRetryMode: string
{
    case None = 'none';
    case Manual = 'manual';
    case Automatic = 'automatic';
}
