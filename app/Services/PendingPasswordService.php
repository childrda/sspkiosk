<?php

namespace App\Services;

use App\Enums\PasswordOrigin;
use App\Enums\PendingPasswordType;
use App\Enums\ResetPasswordMode;
use App\Models\PasswordResetRequest;
use App\Models\PasswordResetRevision;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PendingPasswordService
{
    private function revisions(): PasswordRevisionService
    {
        return app(PasswordRevisionService::class);
    }

    public function hasEncryptedPendingPassword(PasswordResetRequest $request): bool
    {
        $active = $request->activeRevision;

        if ($active !== null) {
            return $active->hasEncryptedPendingPassword();
        }

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
        $write = function () use (
            $request,
            $plainPassword,
            $type,
            $origin,
            $forceChangeAtNextLogin,
            $supersededStudentSelected,
            $passwordMode,
        ): void {
            $locked = PasswordResetRequest::query()->lockForUpdate()->findOrFail($request->id);
            $revision = $this->revisions()->ensureActiveRevisionForStore($locked);

            $this->storeOnRevision(
                $revision,
                $plainPassword,
                $type,
                $origin,
                $forceChangeAtNextLogin,
                $passwordMode ?? $locked->reset_mode,
            );

            if ($supersededStudentSelected) {
                $locked->forceFill(['superseded_student_selected_password' => true])->save();
            }

            $this->revisions()->projectToRequest($locked->fresh(), $revision->fresh());
        };

        if (DB::transactionLevel() > 0) {
            $write();

            return;
        }

        DB::transaction($write);
    }

    public function storeOnRevision(
        PasswordResetRevision $revision,
        string $plainPassword,
        PendingPasswordType $type,
        ?PasswordOrigin $origin = null,
        ?bool $forceChangeAtNextLogin = null,
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
            ?? ($resolvedOrigin === PasswordOrigin::StudentSelected
                ? ResetPasswordMode::StudentSelectedPendingApproval->value
                : ResetPasswordMode::TemporaryGenerated->value);

        $forceChange = $forceChangeAtNextLogin ?? match ($resolvedOrigin) {
            PasswordOrigin::StudentSelected => (bool) config('student-password-reset.google_force_change_at_next_login.student_selected'),
            PasswordOrigin::OfficeGeneratedTemporary => true,
            PasswordOrigin::TemporaryGenerated => (bool) config('student-password-reset.google_force_change_at_next_login.temporary_generated'),
        };

        $request = $revision->request ?? PasswordResetRequest::query()->find($revision->password_reset_request_id);

        $revision->forceFill([
            'encrypted_pending_password' => $encrypted,
            'pending_password_created_at' => now(),
            'pending_password_displayed_at' => null,
            'pending_password_deleted_at' => null,
            'pending_password_printed_at' => null,
            'pending_password_expires_at' => $request?->expires_at,
            'pending_password_type' => $type->value,
            'password_mode' => $resolvedMode,
            'password_origin' => $resolvedOrigin->value,
            'force_change_at_next_login' => $forceChange,
            'retry_available' => false,
            // Keep existing directory_results on replacement revisions; clear only when creating fresh credential on empty snapshot.
            'directory_results' => $revision->directory_results,
        ])->save();

        // New initial store (no directory snapshot yet) must clear prior results.
        if ($revision->revision_number === 1 && $revision->directory_results === null) {
            $revision->forceFill([
                'directory_results' => null,
            ])->save();
        }
    }

    public function decrypt(PasswordResetRequest $request): ?string
    {
        $active = $request->activeRevision ?? $request->activeRevision()->first();

        if ($active !== null) {
            return $this->decryptRevision($active);
        }

        if (! $this->hasEncryptedPendingPassword($request)) {
            return null;
        }

        $stored = (string) $request->encrypted_pending_password;

        if (! config('student-password-reset.pending_password.encryption_enabled', true)) {
            return $stored;
        }

        return Crypt::decryptString($stored);
    }

    public function decryptRevision(PasswordResetRevision $revision): ?string
    {
        if (! $revision->hasEncryptedPendingPassword()) {
            return null;
        }

        $stored = (string) $revision->encrypted_pending_password;

        if (! config('student-password-reset.pending_password.encryption_enabled', true)) {
            return $stored;
        }

        return Crypt::decryptString($stored);
    }

    public function markDisplayed(PasswordResetRequest $request): void
    {
        $active = $request->activeRevision ?? $request->activeRevision()->first();

        if ($active !== null && $active->pending_password_displayed_at === null) {
            $active->update(['pending_password_displayed_at' => now()]);
            $this->revisions()->projectToRequest($request->fresh(), $active->fresh());

            return;
        }

        if ($request->pending_password_displayed_at === null) {
            $request->update(['pending_password_displayed_at' => now()]);
        }
    }

    public function canDisplayOnce(PasswordResetRequest $request): bool
    {
        return $this->hasEncryptedPendingPassword($request)
            && ($request->activeRevision?->pending_password_displayed_at ?? $request->pending_password_displayed_at) === null
            && $request->status->value === 'pending';
    }

    public function delete(PasswordResetRequest $request, string $reason): void
    {
        if (! $this->shouldDeleteOn($reason)) {
            return;
        }

        $write = function () use ($request): void {
            $locked = PasswordResetRequest::query()->lockForUpdate()->find($request->id);
            if ($locked === null) {
                return;
            }

            $active = $this->revisions()->activeRevision($locked);

            if ($active !== null) {
                $active->forceFill([
                    'encrypted_pending_password' => null,
                    'pending_password_deleted_at' => now(),
                    'retry_available' => false,
                ])->save();
                $this->revisions()->projectToRequest($locked, $active);

                return;
            }

            $locked->forceFill([
                'encrypted_pending_password' => null,
                'pending_password_deleted_at' => now(),
                'retry_available' => false,
            ])->save();
        };

        if (DB::transactionLevel() > 0) {
            $write();

            return;
        }

        DB::transaction($write);
    }

    public function deleteRevision(PasswordResetRevision $revision, string $reason): void
    {
        if (! $this->shouldDeleteOn($reason)) {
            return;
        }

        $revision->forceFill([
            'encrypted_pending_password' => null,
            'pending_password_deleted_at' => now(),
            'retry_available' => false,
        ])->save();

        $request = $revision->request ?? PasswordResetRequest::query()->find($revision->password_reset_request_id);
        if ($request !== null) {
            $this->revisions()->projectToRequest($request, $revision);
        }
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
