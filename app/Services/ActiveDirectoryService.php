<?php

namespace App\Services;

use App\Contracts\DirectoryPasswordResetter;
use App\Enums\DirectoryRetryMode;
use App\Exceptions\ActiveDirectoryException;
use App\Models\Student;
use Illuminate\Support\Str;
use LdapRecord\Configuration\ConfigurationException;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Models\ActiveDirectory\User;

class ActiveDirectoryService implements DirectoryPasswordResetter
{
    /**
     * Named LdapRecord container connection (v4 registers names on Container, not Connection).
     */
    public const CONNECTION_NAME = 'sspkiosk-ad';

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
            $this->registerConnection();

            $users = User::on(self::CONNECTION_NAME)
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
            // LdapRecord v4: reset via unicodepwd mutator (no public setPassword()).
            $user->unicodepwd = $password;
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
     * LdapRecord v4 connection options (directorytree/ldaprecord 4.0.6).
     *
     * LDAPS: use_tls=true (ldaps:// on 636). StartTLS (use_starttls) is prohibited.
     * Removed v3 keys use_ssl / old use_tls-as-StartTLS must not be passed.
     *
     * @return array<string, mixed>
     */
    public function ldapConnectionConfig(): array
    {
        return [
            'hosts' => config('active-directory.hosts'),
            'base_dn' => config('active-directory.base_dn'),
            'username' => config('active-directory.username'),
            'password' => config('active-directory.password'),
            'port' => (int) config('active-directory.port', 636),
            // LdapRecord v4: use_tls enables ldaps://. There is no "encryption" key in 4.0.6.
            'use_tls' => true,
            'use_starttls' => false,
            'timeout' => (int) config('active-directory.timeout', 10),
            'options' => extension_loaded('ldap') ? [
                LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_DEMAND,
            ] : [],
        ];
    }

    /**
     * Build and register the named container connection used by User::on().
     * Naming is Container-managed in v4 — Connection has no setName()/getName().
     */
    public function registerConnection(): Connection
    {
        $connection = new Connection($this->ldapConnectionConfig());
        Container::addConnection($connection, self::CONNECTION_NAME);

        return $connection;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     port: int,
     *     bind_ok: bool|null,
     *     ou_readable: bool|null,
     *     sample_status: string|null,
     *     reason: string|null,
     *     exception_class: string|null,
     *     exception_message: string|null,
     *     message: string
     * }
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
            'reason' => null,
            'exception_class' => null,
            'exception_message' => null,
            'message' => '',
        ];

        if (! $enabled) {
            $result['message'] = 'Active Directory is disabled (AD_ENABLED=false).';

            return $result;
        }

        if (! $configured) {
            $result['message'] = 'Active Directory configuration is incomplete or LDAPS (636) is not available.';
            $result['reason'] = 'not_configured';

            return $result;
        }

        try {
            $connection = $this->registerConnection();
            $connection->connect();
            $result['bind_ok'] = true;

            $ou = (string) config('active-directory.student_ou');
            $connection->query()->in($ou)->limit(1)->get();
            $result['ou_readable'] = true;

            if ($sampleSam !== null && $sampleSam !== '') {
                $users = User::on(self::CONNECTION_NAME)
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
            $mapped = $exception instanceof ActiveDirectoryException
                ? $exception
                : $this->mapLdapException($exception);

            $result['bind_ok'] = false;
            $result['reason'] = $mapped->reason;
            $result['exception_class'] = $exception::class;
            $result['exception_message'] = $this->sanitizeExceptionMessage($exception);
            $result['message'] = 'Active Directory health check failed: '.$mapped->getMessage()
                .' (reason: '.$mapped->reason.')';
        }

        return $result;
    }

    private function hostsConfigured(): bool
    {
        $hosts = config('active-directory.hosts', []);

        return is_array($hosts) && $hosts !== [];
    }

    public function mapLdapException(\Throwable $exception): ActiveDirectoryException
    {
        if ($exception instanceof ConfigurationException) {
            return new ActiveDirectoryException(
                'Active Directory connection configuration is invalid.',
                'configuration_error',
                DirectoryRetryMode::None,
                $exception,
            );
        }

        // Programming/API errors (undefined method, ArgumentCountError, TypeError, …).
        // Never disguise these as connection_failed.
        if ($exception instanceof \Error) {
            return new ActiveDirectoryException(
                'Active Directory integration error.',
                'unexpected_error',
                DirectoryRetryMode::None,
                $exception,
            );
        }

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

    private function sanitizeExceptionMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $password = (string) config('active-directory.password', '');

        if ($password !== '' && str_contains($message, $password)) {
            $message = str_replace($password, '[redacted]', $message);
        }

        return $message;
    }
}
