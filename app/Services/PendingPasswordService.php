<?php

namespace App\Services;

use App\Enums\PendingPasswordType;
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

    public function store(PasswordResetRequest $request, string $plainPassword, PendingPasswordType $type): void
    {
        $encrypted = config('student-password-reset.pending_password.encryption_enabled', true)
            ? Crypt::encryptString($plainPassword)
            : $plainPassword;

        $request->forceFill([
            'encrypted_pending_password' => $encrypted,
            'pending_password_created_at' => now(),
            'pending_password_displayed_at' => null,
            'pending_password_deleted_at' => null,
            'pending_password_expires_at' => $request->expires_at,
            'pending_password_type' => $type->value,
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
        ])->save();
    }

    public function deleteOnGoogleFailure(PasswordResetRequest $request): void
    {
        if (config('student-password-reset.pending_password.retain_on_google_failure', false)) {
            return;
        }

        $this->delete($request, 'google_failure');
    }

    private function shouldDeleteOn(string $reason): bool
    {
        return match ($reason) {
            'approval' => config('student-password-reset.pending_password.delete_on_approval', true),
            'denial' => config('student-password-reset.pending_password.delete_on_denial', true),
            'escalation' => config('student-password-reset.pending_password.delete_on_denial', true),
            'expiration' => config('student-password-reset.pending_password.delete_on_expiration', true),
            'google_failure' => config('student-password-reset.pending_password.delete_on_google_failure', true),
            default => true,
        };
    }
}
