<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Driver\Sync\SyncEventLoop;
use LaraGram\MTProto\Listening\ClientListener;
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
