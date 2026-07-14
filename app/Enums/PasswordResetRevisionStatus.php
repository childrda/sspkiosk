<?php

namespace App\Enums;

enum PasswordResetRevisionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
