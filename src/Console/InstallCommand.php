<?php

namespace Hibira\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $signature = 'hibira:install
        {--force : Overwrite resources/js/echo.js if it exists}
        {--no-echo : Do not write resources/js/echo.js}';

    protected $description = 'Set up Hibira: .env keys, the hibira broadcast connection and a Laravel Echo client';

    /** Keys added to .env and .env.example when missing. */
    public const ENV = [
        'HIBIRA_APP_ID' => '',
        'HIBIRA_APP_KEY' => '',
        'HIBIRA_APP_SECRET' => '',
        'HIBIRA_HOST' => 'ws.hibira.io',
        'HIBIRA_PORT' => '443',
        'HIBIRA_SCHEME' => 'https',
    ];

    /** Settings the browser bundle reads, without the VITE_ or MIX_ prefix. */
    public const CLIENT_ENV = ['HIBIRA_APP_KEY', 'HIBIRA_HOST', 'HIBIRA_PORT', 'HIBIRA_SCHEME'];

    public function handle(Filesystem $files): int
    {
        $bundler = $this->bundler($files);

        foreach (['.env', '.env.example'] as $name) {
            $path = base_path($name);
            if (! $files->exists($path)) {
                continue;
            }
            $added = $this->writeEnv($files, $path, $bundler);
            $this->info($added === 0 ? "$name already set up." : "Added $added Hibira settings to $name.");
        }

        if (! $this->option('no-echo')) {
            $this->writeEcho($files, $bundler);
        }

        if (! $files->exists(base_path('routes/channels.php'))) {
            $this->warn('No routes/channels.php yet. Run `php artisan install:broadcasting --without-reverb --without-node` to add it, then run this command again.');
        }

        $this->line('');
        $this->line('  Next:');
        $this->line('  1. Copy the app ID, key and secret from your app page on <comment>https://hibira.io/portal</comment> into .env.');
        $this->line('  2. <comment>npm install laravel-echo pusher-js</comment> and import "./echo" in resources/js/app.js.');
        $this->line('  3. <comment>php artisan hibira:check</comment> to test the connection.');

        return 0;
    }

    /** "vite" (Laravel 9.19 and later) or "mix" (older apps). */
    protected function bundler(Filesystem $files): string
    {
        foreach (['vite.config.js', 'vite.config.ts', 'vite.config.mjs'] as $name) {
            if ($files->exists(base_path($name))) {
                return 'vite';
            }
        }

        return $files->exists(base_path('webpack.mix.js')) ? 'mix' : 'vite';
    }

    /** BROADCAST_CONNECTION since Laravel 11, BROADCAST_DRIVER before. */
    protected function connectionKey(): string
    {
        return version_compare($this->laravel->version(), '11.0.0', '>=') ? 'BROADCAST_CONNECTION' : 'BROADCAST_DRIVER';
    }

    protected function writeEnv(Filesystem $files, string $path, string $bundler): int
    {
        $env = $files->get($path);
        $changed = 0;
        $lines = [];

        if (preg_match('/^BROADCAST_(CONNECTION|DRIVER)=(.*)$/m', $env, $m)) {
            if (trim($m[2]) !== 'hibira') {
                $env = preg_replace('/^BROADCAST_(CONNECTION|DRIVER)=.*$/m', 'BROADCAST_$1=hibira', $env, 1);
                $changed++;
            }
        } else {
            $lines[] = $this->connectionKey().'=hibira';
        }

        $wanted = self::ENV;
        foreach (self::CLIENT_ENV as $key) {
            $wanted[strtoupper($bundler).'_'.$key] = '"${'.$key.'}"';
        }

        foreach ($wanted as $key => $value) {
            if (! preg_match('/^'.preg_quote($key, '/').'=/m', $env)) {
                $lines[] = "$key=$value";
            }
        }

        if ($lines !== []) {
            $env = rtrim($env, "\n")."\n\n".implode("\n", $lines)."\n";
        }

        if ($changed += count($lines)) {
            $files->put($path, $env);
        }

        return $changed;
    }

    protected function writeEcho(Filesystem $files, string $bundler): void
    {
        $target = resource_path('js/echo.js');

        if ($files->exists($target) && ! $this->option('force')) {
            if (strpos($files->get($target), 'HIBIRA_APP_KEY') === false) {
                $this->warn('resources/js/echo.js exists and does not use Hibira. Run with --force to replace it.');
            }

            return;
        }

        if (! $files->isDirectory(dirname($target))) {
            $files->makeDirectory(dirname($target), 0755, true);
        }
        $files->copy(__DIR__.'/../../stubs/echo'.($bundler === 'mix' ? '.mix' : '').'.js', $target);
        $this->info('Wrote resources/js/echo.js.');
    }
}
