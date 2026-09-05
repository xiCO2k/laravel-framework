<?php

namespace Illuminate\Tests\Integration\Foundation;

use Illuminate\Foundation\Cloud;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;

class CloudTest extends TestCase
{
    #[WithConfig('database.connections.pgsql', ['host' => 'test-pooler.pg.laravel.cloud', 'username' => 'test-username', 'password' => 'test-password'])]
    public function test_it_can_resolve_core_container_aliases()
    {
        Cloud::configureUnpooledPostgresConnection($this->app);

        $this->assertEquals([
            'host' => 'test.pg.laravel.cloud',
            'username' => 'test-username',
            'password' => 'test-password',
        ], $this->app['config']->get('database.connections.pgsql-unpooled'));
    }

    public function test_it_can_configure_disks()
    {
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode(
            [
                [
                    'disk' => 'test-disk',
                    'access_key_id' => 'test-access-key-id',
                    'access_key_secret' => 'test-access-key-secret',
                    'bucket' => 'test-bucket',
                    'url' => 'test-url',
                    'endpoint' => 'test-endpoint',
                    'is_default' => false,
                ],
                [
                    'disk' => 'test-disk-2',
                    'access_key_id' => 'test-access-key-id-2',
                    'access_key_secret' => 'test-access-key-secret-2',
                    'bucket' => 'test-bucket-2',
                    'url' => 'test-url-2',
                    'endpoint' => 'test-endpoint-2',
                    'is_default' => true,
                ],
            ]
        );

        Cloud::configureDisks($this->app);

        $this->assertSame('test-disk-2', $this->app['config']->get('filesystems.default'));
        $this->assertSame('test-access-key-id', $this->app['config']->get('filesystems.disks.test-disk.key'));

        unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG']);
    }

    public function test_it_can_configure_scoped_disks()
    {
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode(
            [
                [
                    'disk' => 'test-disk',
                    'access_key_id' => 'test-access-key-id',
                    'access_key_secret' => 'test-access-key-secret',
                    'bucket' => 'test-bucket',
                    'url' => 'test-url',
                    'endpoint' => 'test-endpoint',
                ],
                [
                    'disk' => 'test-disk-scoped',
                    'scoped_disk' => 'test-disk',
                    'prefix' => 'test/prefix/',
                    'is_default' => true,
                ],
            ]
        );

        Cloud::configureDisks($this->app);

        $this->assertSame('scoped', $this->app['config']->get('filesystems.disks.test-disk-scoped.driver'));
        $this->assertSame('test-disk', $this->app['config']->get('filesystems.disks.test-disk-scoped.disk'));

        unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG']);
    }

    public function test_it_can_configure_databases()
    {
        $_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'] = json_encode([
            [
                'connection' => 'main',
                'is_default' => true,
                'driver' => 'mysql',
                'host' => 'test-host.mysql.laravel.cloud',
                'port' => 3306,
                'database' => 'main',
                'username' => 'test-username',
                'password' => 'test-password',
            ],
            [
                'connection' => 'analytics',
                'is_default' => false,
                'driver' => 'pgsql',
                'host' => 'test-host.pg.laravel.cloud',
                'port' => 5432,
                'database' => 'analytics',
                'username' => 'test-username-2',
                'password' => 'test-password-2',
            ],
        ]);

        $defaultConnection = $this->app['config']->get('database.default');

        Cloud::configureDatabases($this->app);

        $this->assertSame('mysql', $this->app['config']->get('database.connections.main.driver'));
        $this->assertSame('test-host.mysql.laravel.cloud', $this->app['config']->get('database.connections.main.host'));
        $this->assertSame('main', $this->app['config']->get('database.connections.main.database'));
        $this->assertSame('utf8mb4', $this->app['config']->get('database.connections.main.charset'));

        $this->assertSame('pgsql', $this->app['config']->get('database.connections.analytics.driver'));
        $this->assertSame('test-username-2', $this->app['config']->get('database.connections.analytics.username'));
        $this->assertSame('utf8', $this->app['config']->get('database.connections.analytics.charset'));

        $this->assertSame($defaultConnection, $this->app['config']->get('database.default'));

        unset($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG']);
    }

    #[WithConfig('database.connections.mysql.strict', false)]
    public function test_it_inherits_the_base_connection_configuration_when_configuring_databases()
    {
        $_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'] = json_encode([
            [
                'connection' => 'main',
                'is_default' => true,
                'driver' => 'mysql',
                'host' => 'test-host.mysql.laravel.cloud',
                'port' => 3306,
                'database' => 'main',
                'username' => 'test-username',
                'password' => 'test-password',
            ],
        ]);

        Cloud::configureDatabases($this->app);

        $this->assertFalse($this->app['config']->get('database.connections.main.strict'));

        unset($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG']);
    }

    #[WithConfig('database.connections.mysql.url', 'mysql://base-url')]
    public function test_it_ignores_the_base_connection_url_when_configuring_databases()
    {
        $_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'] = json_encode([
            [
                'connection' => 'main',
                'is_default' => true,
                'driver' => 'mysql',
                'host' => 'test-host.mysql.laravel.cloud',
                'port' => 3306,
                'database' => 'main',
                'username' => 'test-username',
                'password' => 'test-password',
            ],
        ]);

        Cloud::configureDatabases($this->app);

        $this->assertNull($this->app['config']->get('database.connections.main.url'));
        $this->assertSame('test-host.mysql.laravel.cloud', $this->app['config']->get('database.connections.main.host'));

        unset($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG']);
    }

    public function test_it_configures_unpooled_variants_for_pooled_postgres_databases()
    {
        $_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'] = json_encode([
            [
                'connection' => 'main',
                'is_default' => true,
                'driver' => 'mysql',
                'host' => 'test-host.mysql.laravel.cloud',
                'port' => 3306,
                'database' => 'main',
                'username' => 'test-username',
                'password' => 'test-password',
            ],
            [
                'connection' => 'analytics',
                'is_default' => false,
                'driver' => 'pgsql',
                'host' => 'test-pooler.pg.laravel.cloud',
                'port' => 5432,
                'database' => 'analytics',
                'username' => 'test-username-2',
                'password' => 'test-password-2',
            ],
        ]);

        Cloud::configureDatabases($this->app);

        $this->assertSame('test.pg.laravel.cloud', $this->app['config']->get('database.connections.analytics-unpooled.host'));
        $this->assertTrue($this->app['config']->get('database.connections.analytics.options')[\PDO::ATTR_EMULATE_PREPARES]);
        $this->assertNull($this->app['config']->get('database.connections.main-unpooled'));

        unset($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG']);
    }

    public function test_it_throws_when_the_database_config_is_malformed()
    {
        $_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'] = '[{"connection":';

        try {
            $this->expectException(\JsonException::class);

            Cloud::configureDatabases($this->app);
        } finally {
            unset($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG']);
        }
    }

    public function test_it_respects_log_levels()
    {
        if (isset($_SERVER['LOG_LEVEL'])) {
            $logLevelBackup = $_SERVER['LOG_LEVEL'];
        }

        $_SERVER['LOG_LEVEL'] = 'notice';

        Cloud::configureCloudLogging($this->app);

        $this->assertSame('notice', $this->app['config']->get('logging.channels.laravel-cloud-socket.level'));

        unset($_SERVER['LOG_LEVEL']);

        if (isset($logLevelBackup)) {
            $_SERVER['LOG_LEVEL'] = $logLevelBackup;
        }
    }

    public function test_it_configures_a_cloud_logging_socket_timeout()
    {
        Cloud::configureCloudLogging($this->app);

        $this->assertSame(2.0, $this->app['config']->get('logging.channels.laravel-cloud-socket.with.timeout'));
    }

    public function test_it_aliases_cloud_logging_channel()
    {
        Cloud::configureCloudLogging($this->app);

        $this->assertSame(
            $this->app['config']->get('logging.channels.laravel-cloud-socket'),
            $this->app['config']->get('logging.channels.cloud')
        );
    }

    public function test_it_does_not_replace_existing_cloud_logging_channel()
    {
        $this->app['config']->set('logging.channels.cloud', [
            'driver' => 'single',
            'path' => 'test.log',
        ]);

        Cloud::configureCloudLogging($this->app);

        $this->assertSame([
            'driver' => 'single',
            'path' => 'test.log',
        ], $this->app['config']->get('logging.channels.cloud'));
    }
}
