<?php

namespace Illuminate\Foundation;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Cloud\Events;
use Illuminate\Foundation\Cloud\FailedJobProvider;
use Illuminate\Foundation\Cloud\QueueConnector;
use Illuminate\Queue\Connectors\SqsConnector;
use Monolog\Handler\SocketHandler;
use PDO;

class Cloud
{
    /**
     * The base connection configuration for Laravel Cloud databases.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static $databaseConnectionDefaults = [
        'mysql' => [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],
        'pgsql' => [
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ];

    /**
     * Handle a bootstrapper that is bootstrapping.
     */
    public static function bootstrapperBootstrapping(Application $app, string $bootstrapper): void
    {
        (match ($bootstrapper) {
            BootProviders::class => function () use ($app) {
                static::bootManagedQueues($app);
            },
            default => fn () => true,
        })();
    }

    /**
     * Handle a bootstrapper that has bootstrapped.
     */
    public static function bootstrapperBootstrapped(Application $app, string $bootstrapper): void
    {
        (match ($bootstrapper) {
            LoadConfiguration::class => function () use ($app) {
                static::configureDisks($app);
                static::configureDatabases($app);
                static::configureUnpooledPostgresConnection($app);
                static::ensureMigrationsUseUnpooledConnection($app);
                static::configureManagedQueues($app);
            },
            HandleExceptions::class => function () use ($app) {
                static::configureCloudLogging($app);
            },
            default => fn () => true,
        })();
    }

    /**
     * Configure the Laravel Cloud disks if applicable.
     */
    public static function configureDisks(Application $app): void
    {
        if (! isset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG'])) {
            return;
        }

        $disks = json_decode($_SERVER['LARAVEL_CLOUD_DISK_CONFIG'], true);

        foreach ($disks as $disk) {
            if ($disk['scoped_disk'] ?? false) {
                $app['config']->set('filesystems.disks.'.$disk['disk'], [
                    'driver' => 'scoped',
                    'disk' => $disk['scoped_disk'],
                    'prefix' => $disk['prefix'] ?? '',
                ]);
            } else {
                $app['config']->set('filesystems.disks.'.$disk['disk'], [
                    'driver' => 's3',
                    'key' => $disk['access_key_id'],
                    'secret' => $disk['access_key_secret'],
                    'bucket' => $disk['bucket'],
                    'url' => $disk['url'],
                    'endpoint' => $disk['endpoint'],
                    'region' => 'auto',
                    'use_path_style_endpoint' => false,
                    'throw' => false,
                    'report' => false,
                ]);
            }

            if ($disk['is_default'] ?? false) {
                $app['config']->set('filesystems.default', $disk['disk']);
            }
        }
    }

    /**
     * Configure the Laravel Cloud database connections if applicable.
     *
     * @throws \JsonException
     */
    public static function configureDatabases(Application $app): void
    {
        if (! isset($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'])) {
            return;
        }

        $databases = json_decode($_SERVER['LARAVEL_CLOUD_DATABASE_CONFIG'], associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ($databases as $database) {
            $driver = $database['driver'];

            $app['config']->set('database.connections.'.$database['connection'], array_merge(
                $app['config']->get('database.connections.'.$driver, static::$databaseConnectionDefaults[$driver] ?? []),
                [
                    'driver' => $driver,
                    'url' => null,
                    'host' => $database['host'],
                    'port' => $database['port'],
                    'database' => $database['database'],
                    'username' => $database['username'],
                    'password' => $database['password'],
                ],
            ));

            static::configureUnpooledVariant($app, $database['connection']);
        }
    }

    /**
     * Configure the unpooled Laravel Postgres connection if applicable.
     */
    public static function configureUnpooledPostgresConnection(Application $app): void
    {
        static::configureUnpooledVariant($app, 'pgsql');
    }

    /**
     * Configure an unpooled variant of the given connection if it uses a pooled Laravel Postgres host.
     */
    protected static function configureUnpooledVariant(Application $app, string $connection): void
    {
        $host = $app['config']->get('database.connections.'.$connection.'.host', '');

        if (str_contains($host, 'pg.laravel.cloud') &&
            str_contains($host, '-pooler')) {
            $app['config']->set(
                'database.connections.'.$connection.'-unpooled',
                array_merge($app['config']->get('database.connections.'.$connection), [
                    'host' => str_replace('-pooler', '', $host),
                ])
            );

            $app['config']->set(
                'database.connections.'.$connection.'.options',
                array_replace(
                    $app['config']->get('database.connections.'.$connection.'.options', []),
                    [PDO::ATTR_EMULATE_PREPARES => true],
                ),
            );
        }
    }

    /**
     * Ensure that migrations use the unpooled database connections if applicable.
     */
    public static function ensureMigrationsUseUnpooledConnection(Application $app): void
    {
        $hasUnpooledConnection = false;

        foreach ($app['config']->get('database.connections', []) as $name => $config) {
            if (str_ends_with((string) $name, '-unpooled')) {
                $hasUnpooledConnection = true;

                break;
            }
        }

        if (! $hasUnpooledConnection) {
            return;
        }

        Migrator::resolveConnectionsUsing(function ($resolver, $connection) use ($app) {
            $connection ??= $app['config']->get('database.default');

            return $resolver->connection(
                is_array($app['config']->get('database.connections.'.$connection.'-unpooled'))
                    ? $connection.'-unpooled'
                    : $connection
            );
        });
    }

    /**
     * Configure managed queues if applicable.
     *
     * @throws \JsonException
     */
    public static function configureManagedQueues(Application $app): void
    {
        if (! isset($_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG'])) {
            return;
        }

        $config = json_decode($_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG'], associative: true, flags: JSON_THROW_ON_ERROR);

        $config['connection']['after_commit'] ??= env('CLOUD_QUEUE_AFTER_COMMIT', false);

        $config['connection']['overflow'] ??= [
            'enabled' => env('CLOUD_QUEUE_OVERFLOW_ENABLED', false),
            'store' => env('CLOUD_QUEUE_OVERFLOW_STORE'),
            'always' => env('CLOUD_QUEUE_OVERFLOW_ALWAYS', false),
            'delete_after_processing' => env('CLOUD_QUEUE_OVERFLOW_DELETE_AFTER_PROCESSING', true),
        ];

        $app['config']->set('queue.connections.cloud', $config);
    }

    /**
     * Boot managed queues if applicable.
     */
    public static function bootManagedQueues(Application $app): void
    {
        if ($app['config']->get('queue.connections.cloud.driver') !== 'cloud') {
            return;
        }

        $app->singleton(Events::class, fn () => new Events(Cloud::socket()));
        $app->bind(QueueConnector::class, fn ($app) => new QueueConnector(new SqsConnector, $app));

        $app['queue']->addConnector('cloud', $app->factory(QueueConnector::class));

        $failer = $app['queue.failer'];
        unset($app['queue.failer']);

        $app->singleton('queue.failer', fn ($app) => new FailedJobProvider(
            $failer, $app[Events::class], $app['encrypter'],
        ));
    }

    /**
     * Configure the Laravel Cloud log channels.
     */
    public static function configureCloudLogging(Application $app): void
    {
        $app['config']->set('logging.channels.stderr.formatter_with', [
            'includeStacktraces' => true,
        ]);

        $channel = [
            'driver' => 'monolog',
            'level' => $_ENV['LOG_LEVEL'] ?? $_SERVER['LOG_LEVEL'] ?? 'debug',
            'handler' => SocketHandler::class,
            'formatter' => LaravelCloudJsonFormatter::class,
            'formatter_with' => [
                'includeStacktraces' => true,
            ],
            'with' => [
                'connectionString' => Cloud::socket(),
                'persistent' => true,
                'timeout' => 2.0,
            ],
        ];

        $app['config']->set('logging.channels.laravel-cloud-socket', $channel);

        if (! $app['config']->has('logging.channels.cloud')) {
            $app['config']->set('logging.channels.cloud', $channel);
        }
    }

    /**
     * The cloud socket address.
     */
    protected static function socket(): string
    {
        return $_ENV['LARAVEL_CLOUD_LOG_SOCKET'] ??
            $_SERVER['LARAVEL_CLOUD_LOG_SOCKET'] ??
                'unix:///tmp/cloud-init.sock';
    }
}
