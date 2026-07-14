<?php

namespace App\Enums;

enum PasswordOrigin: string
{
    case StudentSelected = 'student_selected';
    case TemporaryGenerated = 'temporary_generated';
    case OfficeGeneratedTemporary = 'office_generated_temporary';
}
