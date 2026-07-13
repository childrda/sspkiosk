<?php

namespace App\Models;

use App\Enums\PasswordResetRequestStatus;
use Database\Factories\PasswordResetRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetRequest extends Model
{
    /** @use HasFactory<PasswordResetRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'kiosk_id',
        'kiosk_session_id',
        'status',
        'challenge_questions_presented',
        'challenge_score',
        'reset_photo_id',
        'slack_channel_id',
        'slack_message_ts',
        'requested_at',
        'expires_at',
        'approved_by_slack_user_id',
        'approved_at',
        'denied_by_slack_user_id',
        'denied_at',
        'denial_reason',
        'escalated_at',
        'escalated_by_slack_user_id',
        'office_verified_at',
        'office_verified_by_user_id',
        'office_verification_notes',
        'office_verification_expires_at',
        'google_reset_attempted_at',
        'google_reset_success',
        'google_error_message',
        'reset_mode',
        'encrypted_pending_password',
        'pending_password_created_at',
        'pending_password_displayed_at',
        'pending_password_deleted_at',
        'pending_password_expires_at',
        'pending_password_type',
    ];

    protected function casts(): array
    {
        return [
            'status' => PasswordResetRequestStatus::class,
            'challenge_questions_presented' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'escalated_at' => 'datetime',
            'office_verified_at' => 'datetime',
            'office_verification_expires_at' => 'datetime',
            'google_reset_attempted_at' => 'datetime',
            'google_reset_success' => 'boolean',
            'pending_password_created_at' => 'datetime',
            'pending_password_displayed_at' => 'datetime',
            'pending_password_deleted_at' => 'datetime',
            'pending_password_expires_at' => 'datetime',
        ];
    }

    public function hasEncryptedPendingPassword(): bool
    {
        return $this->encrypted_pending_password !== null
            && $this->encrypted_pending_password !== ''
            && $this->pending_password_deleted_at === null;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function resetPhoto(): BelongsTo
    {
        return $this->belongsTo(StudentPhoto::class, 'reset_photo_id');
    }

    public function isPending(): bool
    {
        return $this->status === PasswordResetRequestStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast()
            || $this->status === PasswordResetRequestStatus::Expired;
    }

    public function isOfficeVerificationExpired(): bool
    {
        return $this->office_verification_expires_at !== null
            && $this->office_verification_expires_at->isPast();
    }

    public function officeVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'office_verified_by_user_id');
    }
}
