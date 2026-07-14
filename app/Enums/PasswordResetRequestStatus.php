<?php

namespace App\Enums;

enum PasswordResetRequestStatus: string
{
    case Pending = 'pending';
    case ApprovedProcessing = 'approved_processing';
    case Completed = 'completed';
    case Denied = 'denied';
    case NeedsOfficeVerification = 'needs_office_verification';
    case Expired = 'expired';
    case Failed = 'failed';
    case PartiallyCompleted = 'partially_completed';

    /** @deprecated Use completed or approved_processing */
    case Approved = 'approved';
}
