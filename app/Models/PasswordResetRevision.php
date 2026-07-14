<?php

namespace App\Models;

use App\Enums\PasswordResetRevisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetRevision extends Model
{
    protected $fillable = [
        'password_reset_request_id',
        'revision_number',
        'password_mode',
        'password_origin',
        'force_change_at_next_login',
        'encrypted_pending_password',
        'pending_password_expires_at',
        'pending_password_created_at',
        'pending_password_displayed_at',
        'pending_password_printed_at',
        'pending_password_deleted_at',
        'pending_password_type',
        'directory_results',
        'retry_available',
        'status',
        'superseded_at',
        'active_for_request_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PasswordResetRevisionStatus::class,
            'directory_results' => 'array',
            'force_change_at_next_login' => 'boolean',
            'retry_available' => 'boolean',
            'pending_password_expires_at' => 'datetime',
            'pending_password_created_at' => 'datetime',
            'pending_password_displayed_at' => 'datetime',
            'pending_password_printed_at' => 'datetime',
            'pending_password_deleted_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PasswordResetRequest::class, 'password_reset_request_id');
    }

    public function isActive(): bool
    {
        return $this->status === PasswordResetRevisionStatus::Active
            && $this->superseded_at === null
            && $this->active_for_request_id !== null;
    }

    public function hasEncryptedPendingPassword(): bool
    {
        return $this->encrypted_pending_password !== null
            && $this->encrypted_pending_password !== ''
            && $this->pending_password_deleted_at === null;
    }

    public function pendingPasswordExpired(): bool
    {
        return $this->pending_password_expires_at !== null
            && $this->pending_password_expires_at->isPast();
    }
}
