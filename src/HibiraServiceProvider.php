<?php

namespace Hibira\Laravel;

use Hibira\Laravel\Console\CheckCommand;
use Hibira\Laravel\Console\InstallCommand;
use Hibira\Laravel\Http\Controllers\PushTokenController;
use Hibira\Laravel\Http\Controllers\WebhookController;
use Hibira\Laravel\Push\HibiraPushChannel;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton(Hibira::class, fn (Application $app) => new Hibira($app['config']['hibira']));
        $this->app->singleton('hibira', fn (Application $app) => $app->make(Hibira::class)->realtime());
        $this->app->singleton('hibira.push', fn (Application $app) => $app->make(Hibira::class)->push());

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
        $this->callAfterResolving(BroadcastManager::class, function (BroadcastManager $manager) {
            $manager->extend('hibira', function (Application $app, array $config) {
                // A connection may override any key of config/hibira.php.
                $realtime = array_diff_key($config, ['driver' => true]) === []
                    ? $app->make('hibira')
                    : (new Hibira(array_replace($app['config']['hibira'], $config)))->realtime();

                return new PusherBroadcaster($realtime);
            });
        });

        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $channels) {
            $channels->extend('hibira-push', fn (Application $app) => $app->make(HibiraPushChannel::class));
        });

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/hibira.php' => config_path('hibira.php')], 'hibira-config');
            $this->publishes([__DIR__.'/../stubs/echo.js' => resource_path('js/echo.js')], 'hibira-echo');

            $this->commands([InstallCommand::class, CheckCommand::class]);
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
