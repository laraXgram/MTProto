<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Driver\Sync\SyncEventLoop;
use LaraGram\MTProto\Listening\ClientListener;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\Runtime\SwooleRuntime;
use LaraGram\MTProto\Updates\UpdatesHandler;
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

    /**
     * Bind the coroutine {@see Runtime} (RULE 1). The Swoole hooks are only
     * enabled when the driver is actually 'swoole', so the sync path keeps
     * native blocking semantics. A future RoadRunner/FrankenPHP backend = bind a
     * different Runtime here; nothing in Core changes.
     */
    protected function registerRuntime(): void
    {
        $this->app->singleton(Runtime::class, function ($app) {
            $driver = $app['config']['mtproto.driver'] ?? 'sync';

            return new SwooleRuntime(enableHooks: $driver === 'swoole');
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
