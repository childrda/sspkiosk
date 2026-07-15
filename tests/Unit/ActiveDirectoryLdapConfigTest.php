<?php

namespace Tests\Unit;

use App\Enums\DirectoryRetryMode;
use App\Services\ActiveDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LdapRecord\Configuration\ConfigurationException;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Models\ActiveDirectory\User;
use Tests\TestCase;

class ActiveDirectoryLdapConfigTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'active-directory.enabled' => true,
            'active-directory.hosts' => ['dc1.example.org'],
            'active-directory.port' => 636,
            'active-directory.base_dn' => 'DC=example,DC=org',
            'active-directory.student_ou' => 'OU=Students,DC=example,DC=org',
            'active-directory.username' => 'CN=sspkiosk,OU=Service Accounts,DC=example,DC=org',
            'active-directory.password' => 'super-secret-bind-password',
            'active-directory.use_ssl' => true,
            'active-directory.timeout' => 10,
        ]);
    }

    public function test_ldap_connection_config_uses_v4_tls_keys_not_removed_v3_keys(): void
    {
        $config = app(ActiveDirectoryService::class)->ldapConnectionConfig();

        $this->assertSame(['dc1.example.org'], $config['hosts']);
        $this->assertSame('DC=example,DC=org', $config['base_dn']);
        $this->assertSame('CN=sspkiosk,OU=Service Accounts,DC=example,DC=org', $config['username']);
        $this->assertSame('super-secret-bind-password', $config['password']);
        $this->assertSame(636, $config['port']);
        $this->assertSame(10, $config['timeout']);
        $this->assertArrayHasKey('options', $config);

        // LdapRecord 4.0.6: LDAPS is use_tls=true; StartTLS is use_starttls.
        $this->assertTrue($config['use_tls']);
        $this->assertFalse($config['use_starttls']);

        $this->assertArrayNotHasKey('use_ssl', $config);
        $this->assertArrayNotHasKey('encryption', $config);

        // Constructing Connection must accept this config (no ConfigurationException).
        $connection = new Connection($config);
        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function test_removed_v3_use_ssl_key_is_rejected_by_ldaprecord(): void
    {
        $config = app(ActiveDirectoryService::class)->ldapConnectionConfig();
        $config['use_ssl'] = true;

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Option use_ssl does not exist.');

        new Connection($config);
    }

    public function test_configuration_exception_maps_to_configuration_error(): void
    {
        $mapped = app(ActiveDirectoryService::class)->mapLdapException(
            new ConfigurationException('Option use_ssl does not exist.'),
        );

        $this->assertSame('configuration_error', $mapped->reason);
        $this->assertSame(DirectoryRetryMode::None, $mapped->retryMode);
        $this->assertStringContainsString('configuration is invalid', $mapped->getMessage());
    }

    public function test_register_connection_uses_container_name_without_setname_getname(): void
    {
        Container::getNewInstance();

        $service = app(ActiveDirectoryService::class);
        $source = file_get_contents(app_path('Services/ActiveDirectoryService.php'));

        $this->assertStringNotContainsString('->setName(', $source);
        $this->assertStringNotContainsString('->getName()', $source);
        $this->assertStringNotContainsString('->setPassword(', $source);
        $this->assertStringContainsString('unicodepwd', $source);
        $this->assertStringContainsString('Container::addConnection', $source);
        $this->assertStringContainsString('User::on(self::CONNECTION_NAME)', $source);

        $connection = $service->registerConnection();

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertFalse(method_exists($connection, 'setName'));
        $this->assertFalse(method_exists($connection, 'getName'));
        $this->assertTrue(Container::hasConnection(ActiveDirectoryService::CONNECTION_NAME));
        $this->assertSame(
            $connection,
            Container::getConnection(ActiveDirectoryService::CONNECTION_NAME),
        );

        // User::on() must resolve against the registered name without a PHP Error.
        $builder = User::on(ActiveDirectoryService::CONNECTION_NAME);
        $this->assertNotNull($builder);
    }

    public function test_php_error_maps_to_unexpected_error_not_connection_failed(): void
    {
        $mapped = app(ActiveDirectoryService::class)->mapLdapException(
            new \Error('Call to undefined method LdapRecord\Connection::setName()'),
        );

        $this->assertSame('unexpected_error', $mapped->reason);
        $this->assertSame(DirectoryRetryMode::None, $mapped->retryMode);
        $this->assertStringContainsString('integration error', $mapped->getMessage());
    }

    public function test_ad_check_prints_reason_on_incomplete_config(): void
    {
        config([
            'active-directory.enabled' => true,
            'active-directory.hosts' => [],
            'active-directory.use_ssl' => true,
            'active-directory.port' => 636,
        ]);

        $this->artisan('ssp:ad-check')
            ->expectsOutputToContain('Reason: not_configured')
            ->expectsOutputToContain('Active Directory configuration is incomplete')
            ->assertFailed();
    }

    public function test_ad_check_debug_never_prints_bind_password(): void
    {
        config([
            'active-directory.enabled' => false,
            'active-directory.password' => 'must-not-appear-in-output',
        ]);

        $this->artisan('ssp:ad-check', ['--debug' => true])
            ->expectsOutputToContain('AD enabled: no')
            ->doesntExpectOutputToContain('must-not-appear-in-output')
            ->assertSuccessful();
    }
}
