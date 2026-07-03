<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Listening\ClientListener;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\Runtime\SwooleRuntime;
use LaraGram\Support\ServiceProvider;

/**
 * Service provider for the MTProto Client package.
 */
class ClientServiceProvider extends ServiceProvider
{
    /**
     * Register bindings into the container.
     */
    public function register(): void
    {
        $this->mergeConfig();
        $this->registerLogger();
        $this->registerRuntime();
        $this->registerClientListener();
        $this->registerClientKernel();
        $this->registerClientManager();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->publishConfig();
        $this->registerFacadeAlias();
        $this->registerClientMiddlewareGroup();
        $this->registerSurgeTables();
        $this->registerSurgePumpProcess();
    }

    protected function registerSurgeTables(): void
    {
        if (!$this->app->bound('surge') && !class_exists(\LaraGram\Surge\Facades\Surge::class)) {
            return;
        }

        $config = $this->app['config'];
        $mtproto = (array) ($config['mtproto'] ?? []);

        $storeSets = [(array) ($mtproto['stores'] ?? [])];
        foreach ((array) ($mtproto['sessions'] ?? []) as $session) {
            if (is_array($session) && isset($session['stores'])) {
                $storeSets[] = (array) $session['stores'];
            }
        }

        $tables = (array) ($config['surge.tables'] ?? []);
        $added = false;

        foreach ($storeSets as $stores) {
            foreach ($stores as $store) {
                if (!is_array($store)) {
                    continue;
                }

                $driver = strtolower((string) ($store['driver'] ?? ''));
                if ($driver !== 'swoole-table' && $driver !== 'swoole_table') {
                    continue;
                }

                $name = (string) ($store['table'] ?? 'mtproto');
                $rows = (int) ($store['rows'] ?? 1024);
                $size = (int) ($store['size'] ?? 8192);
                $key = "{$name}:{$rows}";

                if (!isset($tables[$key])) {
                    $tables[$key] = ['v' => "string:{$size}"];
                    $added = true;
                }
            }
        }

        if ($added) {
            $config['surge.tables'] = $tables;
        }
    }

    protected function registerSurgePumpProcess(): void
    {
        if (!($this->app['config']['mtproto.surge.autostart'] ?? true)) {
            return;
        }

        if (!$this->app->bound('surge') || !class_exists(\LaraGram\Surge\Facades\Surge::class)) {
            return;
        }

        \LaraGram\Surge\Facades\Surge::process(
            \LaraGram\MTProto\Foundation\PumpProcess::class,
            'mtproto-pump',
        );
    }

    protected function registerClientMiddlewareGroup(): void
    {
        $this->app['client.listener']->middlewareGroup('client', [
            \LaraGram\MTProto\Listening\Middleware\ClientSubstituteBindings::class,
        ]);
    }

    protected function mergeConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/mtproto.php', 'mtproto'
        );
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/mtproto.php' => $this->app->configPath('mtproto.php'),
        ], 'mtproto-config');
    }

    /**
     * Register the 'Client' facade alias so the package is self-contained.
     */
    protected function registerFacadeAlias(): void
    {
        \LaraGram\Foundation\AliasLoader::getInstance()->alias(
            'Client',
            \LaraGram\MTProto\Facades\Client::class,
        );
    }

    protected function registerClientListener(): void
    {
        $this->app->singleton('client.listener', function ($app) {
            return new ClientListener($app['events'], $app);
        });

        $this->app->alias('client.listener', ClientListener::class);
    }

    protected function registerClientKernel(): void
    {
        $this->app->singleton(ClientKernel::class, function ($app) {
            return new ClientKernel($app, $app['client.listener']);
        });
    }

    protected function registerLogger(): void
    {
        $this->app->singleton('mtproto.logger', function ($app) {
            $channel = $app['config']['mtproto.log_channel'] ?? null;

            return $app['log']->channel($channel);
        });
    }

    protected function registerRuntime(): void
    {
        $this->app->singleton(Runtime::class, function ($app) {
            $driver = $app['config']['mtproto.driver'] ?? 'sync';
            $hooks = $driver === 'swoole';

            return new \LaraGram\MTProto\Runtime\SurgeRuntime(
                surgeTableResolver: static function (string $name) {
                    if (!class_exists(\LaraGram\Surge\Facades\Surge::class)) {
                        return null;
                    }

                    return \LaraGram\Surge\Facades\Surge::table($name);
                },
                enableHooks: $hooks,
            );
        });
    }

    protected function registerClientManager(): void
    {
        $this->app->singleton('mtproto.manager', function ($app) {
            return new ClientManager($app);
        });
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \LaraGram\MTProto\Console\ClientStartCommand::class,
                \LaraGram\MTProto\Console\ClientAuthCommand::class,
            ]);
        }
    }
}
