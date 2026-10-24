<?php

namespace Hibira\Laravel;

use Hibira\Laravel\Console\CheckCommand;
use Hibira\Laravel\Console\InstallCommand;
use Hibira\Laravel\Http\Controllers\PushTokenController;
use Hibira\Laravel\Http\Controllers\WebhookController;
use Hibira\Laravel\Push\HibiraPushChannel;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pusher\Pusher;
use Pusher\PushNotifications\PushNotifications;

class HibiraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hibira.php', 'hibira');

        // BROADCAST_CONNECTION=hibira works without editing
        // config/broadcasting.php.
        if (! $this->app['config']->has('broadcasting.connections.hibira')) {
            $this->app['config']->set('broadcasting.connections.hibira', ['driver' => 'hibira']);
        }

        $this->app->singleton(Hibira::class, function ($app) {
            return new Hibira($app['config']['hibira']);
        });
        $this->app->singleton('hibira', function ($app) {
            return $app->make(Hibira::class)->realtime();
        });
        $this->app->singleton('hibira.push', function ($app) {
            return $app->make(Hibira::class)->push();
        });

        // Code that type-hints the Pusher SDK classes gets clients that talk
        // to Hibira, unless the app binds its own.
        if (! $this->app->bound(Pusher::class)) {
            $this->app->alias('hibira', Pusher::class);
        }
        if (! $this->app->bound(PushNotifications::class)) {
            $this->app->alias('hibira.push', PushNotifications::class);
        }
    }

    public function boot(): void
    {
        $this->afterResolving(BroadcastManager::class, function (BroadcastManager $manager) {
            $manager->extend('hibira', function ($app, array $config) {
                // A connection may override any key of config/hibira.php.
                $realtime = array_diff_key($config, ['driver' => true]) === []
                    ? $app->make('hibira')
                    : (new Hibira(array_replace($app['config']['hibira'], $config)))->realtime();

                return new PusherBroadcaster($realtime);
            });
        });

        $this->afterResolving(ChannelManager::class, function (ChannelManager $channels) {
            $channels->extend('hibira-push', function ($app) {
                return $app->make(HibiraPushChannel::class);
            });
        });

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/hibira.php' => config_path('hibira.php')], 'hibira-config');
            $this->publishes([__DIR__.'/../stubs/echo.js' => resource_path('js/echo.js')], 'hibira-echo');

            $this->commands([InstallCommand::class, CheckCommand::class]);
        }
    }

    /** callAfterResolving() for Laravel versions before it existed. */
    protected function afterResolving(string $abstract, callable $callback): void
    {
        $this->app->afterResolving($abstract, $callback);

        if ($this->app->resolved($abstract)) {
            $callback($this->app->make($abstract), $this->app);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app['config']['hibira'];

        if ($path = $config['push']['token_route'] ?? null) {
            Route::middleware($config['push']['token_middleware'] ?? ['web', 'auth'])
                ->get($path, PushTokenController::class)
                ->name('hibira.push.token');
        }

        if ($path = $config['webhooks']['path'] ?? null) {
            Route::middleware($config['webhooks']['middleware'] ?? [])
                ->post($path, WebhookController::class)
                ->name('hibira.webhook');
        }
    }
}
