<?php

namespace App\Services;

use App\Enums\PasswordOrigin;
use App\Enums\PendingPasswordType;
use App\Enums\ResetPasswordMode;
use App\Models\PasswordResetRequest;
use Illuminate\Support\Facades\Crypt;

class PendingPasswordService
{
    public function hasEncryptedPendingPassword(PasswordResetRequest $request): bool
    {
        return $request->encrypted_pending_password !== null
            && $request->encrypted_pending_password !== ''
            && $request->pending_password_deleted_at === null;
    }

    public function store(
        PasswordResetRequest $request,
        string $plainPassword,
        PendingPasswordType $type,
        ?PasswordOrigin $origin = null,
        ?bool $forceChangeAtNextLogin = null,
        bool $supersededStudentSelected = false,
        ?string $passwordMode = null,
    ): void {
        $encrypted = config('student-password-reset.pending_password.encryption_enabled', true)
            ? Crypt::encryptString($plainPassword)
            : $plainPassword;

        $resolvedOrigin = $origin ?? match ($type) {
            PendingPasswordType::StudentSelected => PasswordOrigin::StudentSelected,
            PendingPasswordType::TemporaryGenerated => PasswordOrigin::TemporaryGenerated,
        };

        $resolvedMode = $passwordMode
            ?? $request->reset_mode
            ?? ($resolvedOrigin === PasswordOrigin::StudentSelected
                ? ResetPasswordMode::StudentSelectedPendingApproval->value
                : ResetPasswordMode::TemporaryGenerated->value);

        $forceChange = $forceChangeAtNextLogin ?? match ($resolvedOrigin) {
            PasswordOrigin::StudentSelected => (bool) config('student-password-reset.google_force_change_at_next_login.student_selected'),
            PasswordOrigin::OfficeGeneratedTemporary => true,
            PasswordOrigin::TemporaryGenerated => (bool) config('student-password-reset.google_force_change_at_next_login.temporary_generated'),
        };

        $request->forceFill([
            'encrypted_pending_password' => $encrypted,
            'pending_password_created_at' => now(),
            'pending_password_displayed_at' => null,
            'pending_password_deleted_at' => null,
            'pending_password_expires_at' => $request->expires_at,
            'pending_password_type' => $type->value,
            'password_mode' => $resolvedMode,
            'password_origin' => $resolvedOrigin->value,
            'force_change_at_next_login' => $forceChange,
            'superseded_student_selected_password' => $supersededStudentSelected
                || (bool) $request->superseded_student_selected_password,
            'retry_available' => false,
            'directory_results' => null,
            'google_reset_attempted_at' => null,
            'google_reset_success' => null,
            'google_error_message' => null,
        ])->save();
    }

    public function decrypt(PasswordResetRequest $request): ?string
    {
        if (! $this->hasEncryptedPendingPassword($request)) {
            return null;
        }

        $stored = (string) $request->encrypted_pending_password;

        if (! config('student-password-reset.pending_password.encryption_enabled', true)) {
            return $stored;
        }

        return Crypt::decryptString($stored);
    }

    public function markDisplayed(PasswordResetRequest $request): void
    {
        if ($request->pending_password_displayed_at === null) {
            $request->update(['pending_password_displayed_at' => now()]);
        }
    }

    public function canDisplayOnce(PasswordResetRequest $request): bool
    {
        return $this->hasEncryptedPendingPassword($request)
            && $request->pending_password_displayed_at === null
            && $request->status->value === 'pending';
    }

    public function delete(PasswordResetRequest $request, string $reason): void
    {
        if (! $this->shouldDeleteOn($reason)) {
            return;
        }

        if ($request->encrypted_pending_password === null && $request->pending_password_deleted_at !== null) {
            return;
        }

        $request->forceFill([
            'encrypted_pending_password' => null,
            'pending_password_deleted_at' => now(),
            'retry_available' => false,
        ])->save();
    }

    private function shouldDeleteOn(string $reason): bool
    {
        return match ($reason) {
            'approval' => config('student-password-reset.pending_password.delete_on_approval', true),
            'denial' => config('student-password-reset.pending_password.delete_on_denial', true),
            'escalation' => config('student-password-reset.pending_password.delete_on_denial', true),
            'expiration' => config('student-password-reset.pending_password.delete_on_expiration', true),
            default => true,
        };
    }
}
