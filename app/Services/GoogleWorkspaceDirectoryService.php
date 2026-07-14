<?php

namespace App\Services;

use App\Contracts\DirectoryPasswordResetter;
use App\Enums\DirectoryRetryMode;
use App\Exceptions\GoogleWorkspaceException;
use App\Models\Student;
use Google\Client as GoogleClient;
use Google\Service\Directory;
use Google\Service\Directory\User;

class GoogleWorkspaceDirectoryService implements DirectoryPasswordResetter
{
    public function key(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        $path = config('google-workspace.service_account_json_path');

        return $path !== null
            && $path !== ''
            && config('google-workspace.admin_impersonation_email')
            && is_readable($this->resolvePath((string) $path));
    }

    public function supports(Student $student): bool
    {
        return true;
    }

    public function resetPassword(Student $student, string $password, bool $changePasswordAtNextLogin): void
    {
        if (! $this->isConfigured()) {
            throw new GoogleWorkspaceException(
                'Google Workspace Directory API is not configured.',
                'configuration_error',
                DirectoryRetryMode::None,
            );
        }

        try {
            $directory = $this->createDirectoryService();

            $user = new User;
            $user->setPassword($password);
            $user->setChangePasswordAtNextLogin($changePasswordAtNextLogin);

            $directory->users->update($student->email, $user);
        } catch (GoogleWorkspaceException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new GoogleWorkspaceException(
                'Google password reset failed.',
                $this->classifyGoogleReason($exception),
                $this->classifyGoogleRetryMode($exception),
                $exception,
            );
        }
    }

    private function classifyGoogleReason(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'rate') || str_contains($message, 'quota') || str_contains($message, '429')) {
            return 'rate_limited';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }

        if (str_contains($message, 'connection') || str_contains($message, 'network') || str_contains($message, 'unreachable')) {
            return 'connection_failed';
        }

        if (str_contains($message, 'permission') || str_contains($message, 'forbidden') || str_contains($message, '403')) {
            return 'permission_denied';
        }

        if (str_contains($message, 'not found') || str_contains($message, '404')) {
            return 'not_found';
        }

        return 'unexpected_error';
    }

    private function classifyGoogleRetryMode(\Throwable $exception): DirectoryRetryMode
    {
        return match ($this->classifyGoogleReason($exception)) {
            'rate_limited', 'timeout', 'connection_failed' => DirectoryRetryMode::Automatic,
            'permission_denied' => DirectoryRetryMode::Manual,
            default => DirectoryRetryMode::None,
        };
    }

    private function createDirectoryService(): Directory
    {
        $client = new GoogleClient;
        $client->setAuthConfig($this->resolvePath((string) config('google-workspace.service_account_json_path')));
        $client->setScopes(config('google-workspace.directory_scopes'));
        $client->setSubject(config('google-workspace.admin_impersonation_email'));

        return new Directory($client);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:\\\\#', $path)) {
            return $path;
        }

        return base_path($path);
    }
}
