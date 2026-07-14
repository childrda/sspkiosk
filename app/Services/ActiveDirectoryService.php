<?php

namespace App\Services;

use App\Contracts\DirectoryPasswordResetter;
use App\Enums\DirectoryRetryMode;
use App\Exceptions\ActiveDirectoryException;
use App\Models\Student;
use Illuminate\Support\Str;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Models\ActiveDirectory\User;

class ActiveDirectoryService implements DirectoryPasswordResetter
{
    public function key(): string
    {
        return 'active_directory';
    }

    public function isConfigured(): bool
    {
        if (! config('active-directory.enabled')) {
            return false;
        }

        return $this->hostsConfigured()
            && filled(config('active-directory.base_dn'))
            && filled(config('active-directory.student_ou'))
            && filled(config('active-directory.username'))
            && filled(config('active-directory.password'))
            && (int) config('active-directory.port') === 636
            && (bool) config('active-directory.use_ssl') === true
            && extension_loaded('ldap');
    }

    public function supports(Student $student): bool
    {
        return true;
    }

    public function resetPassword(Student $student, string $password, bool $changePasswordAtNextLogin): void
    {
        if (! config('active-directory.enabled')) {
            throw new ActiveDirectoryException(
                'Active Directory is disabled.',
                'disabled',
                DirectoryRetryMode::None,
            );
        }

        if (! $this->isConfigured()) {
            throw new ActiveDirectoryException(
                'Active Directory is not configured.',
                'not_configured',
                DirectoryRetryMode::None,
            );
        }

        $sam = $this->samAccountName($student);

        try {
            $connection = $this->makeConnection();
            Container::addConnection($connection);

            $users = User::on($connection->getName())
                ->in((string) config('active-directory.student_ou'))
                ->where('objectcategory', '=', 'person')
                ->where('objectclass', '=', 'user')
                ->where('samaccountname', '=', $sam)
                ->get();

            if ($users->count() === 0) {
                throw new ActiveDirectoryException(
                    'Active Directory user not found.',
                    'not_found',
                    DirectoryRetryMode::None,
                );
            }

            if ($users->count() > 1) {
                throw new ActiveDirectoryException(
                    'Multiple Active Directory users matched.',
                    'ambiguous_match',
                    DirectoryRetryMode::None,
                );
            }

            /** @var User $user */
            $user = $users->first();
            $user->setPassword($password);
            $user->save();

            $user->update([
                'pwdlastset' => $changePasswordAtNextLogin ? 0 : -1,
            ]);
        } catch (ActiveDirectoryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw $this->mapLdapException($exception);
        }
    }

    public function samAccountName(Student $student): string
    {
        $local = Str::before($student->email, '@');

        if (
            $local === ''
            || strlen($local) > 20
            || ! preg_match('/^[A-Za-z0-9._-]+$/', $local)
        ) {
            throw new ActiveDirectoryException(
                'Cannot derive Active Directory username.',
                'invalid_username',
                DirectoryRetryMode::None,
            );
        }

        return $local;
    }

    /**
     * @return array{enabled: bool, configured: bool, port: int, bind_ok: bool|null, ou_readable: bool|null, sample_status: string|null, message: string}
     */
    public function healthCheck(?string $sampleSam = null): array
    {
        $enabled = (bool) config('active-directory.enabled');
        $configured = $this->isConfigured();
        $result = [
            'enabled' => $enabled,
            'configured' => $configured,
            'port' => (int) config('active-directory.port', 636),
            'bind_ok' => null,
            'ou_readable' => null,
            'sample_status' => null,
            'message' => '',
        ];

        if (! $enabled) {
            $result['message'] = 'Active Directory is disabled (AD_ENABLED=false).';

            return $result;
        }

        if (! $configured) {
            $result['message'] = 'Active Directory configuration is incomplete or LDAPS (636) is not available.';

            return $result;
        }

        try {
            $connection = $this->makeConnection();
            $connection->connect();
            $result['bind_ok'] = true;

            $ou = (string) config('active-directory.student_ou');
            $result['ou_readable'] = $connection->query()->in($ou)->limit(1)->get() !== false;

            if ($sampleSam !== null && $sampleSam !== '') {
                $users = User::on($connection->getName())
                    ->in($ou)
                    ->where('objectcategory', '=', 'person')
                    ->where('objectclass', '=', 'user')
                    ->where('samaccountname', '=', $sampleSam)
                    ->get();

                $result['sample_status'] = match ($users->count()) {
                    0 => 'not_found',
                    1 => 'found',
                    default => 'ambiguous_match',
                };
            }

            $result['message'] = 'Active Directory LDAPS health check succeeded.';
        } catch (\Throwable $exception) {
            $result['bind_ok'] = false;
            $result['message'] = 'Active Directory health check failed.';
        }

        return $result;
    }

    private function hostsConfigured(): bool
    {
        $hosts = config('active-directory.hosts', []);

        return is_array($hosts) && $hosts !== [];
    }

    private function makeConnection(): Connection
    {
        $name = 'sspkiosk-ad';

        $connection = new Connection([
            'hosts' => config('active-directory.hosts'),
            'base_dn' => config('active-directory.base_dn'),
            'username' => config('active-directory.username'),
            'password' => config('active-directory.password'),
            'port' => (int) config('active-directory.port', 636),
            'use_ssl' => true,
            'use_tls' => false,
            'timeout' => (int) config('active-directory.timeout', 10),
            'options' => extension_loaded('ldap') ? [
                LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_DEMAND,
            ] : [],
        ]);

        $connection->setName($name);

        return $connection;
    }

    private function mapLdapException(\Throwable $exception): ActiveDirectoryException
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return new ActiveDirectoryException('Active Directory timed out.', 'timeout', DirectoryRetryMode::Automatic, $exception);
        }

        if (str_contains($message, 'connection') || str_contains($message, 'can\'t contact') || str_contains($message, 'unreachable')) {
            return new ActiveDirectoryException('Active Directory connection failed.', 'connection_failed', DirectoryRetryMode::Automatic, $exception);
        }

        if (str_contains($message, 'unavailable') || str_contains($message, 'server down')) {
            return new ActiveDirectoryException('Domain controller unavailable.', 'dc_unavailable', DirectoryRetryMode::Automatic, $exception);
        }

        if (str_contains($message, 'insufficient access') || str_contains($message, 'permission') || str_contains($message, '50')) {
            return new ActiveDirectoryException('Active Directory permission denied.', 'permission_denied', DirectoryRetryMode::Manual, $exception);
        }

        if (
            str_contains($message, 'will_not_perform')
            || str_contains($message, 'constraint')
            || str_contains($message, 'password')
            || str_contains($message, 'unwilling')
        ) {
            return new ActiveDirectoryException(
                'Active Directory rejected the selected password.',
                'policy_rejected',
                DirectoryRetryMode::None,
                $exception,
            );
        }

        return new ActiveDirectoryException(
            'Active Directory password reset failed.',
            'unexpected_error',
            DirectoryRetryMode::None,
            $exception,
        );
    }
}
