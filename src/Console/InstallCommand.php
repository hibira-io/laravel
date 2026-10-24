<?php

namespace Hibira\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $signature = 'hibira:install
        {--force : Overwrite resources/js/echo.js if it exists}
        {--no-echo : Do not write resources/js/echo.js}';

    protected $description = 'Set up Hibira: .env keys, BROADCAST_CONNECTION=hibira and a Laravel Echo client';

    /** Keys added to .env and .env.example when missing. */
    public const ENV = [
        'HIBIRA_APP_ID' => '',
        'HIBIRA_APP_KEY' => '',
        'HIBIRA_APP_SECRET' => '',
        'HIBIRA_HOST' => 'ws.hibira.io',
        'HIBIRA_PORT' => '443',
        'HIBIRA_SCHEME' => 'https',
        'VITE_HIBIRA_APP_KEY' => '"${HIBIRA_APP_KEY}"',
        'VITE_HIBIRA_HOST' => '"${HIBIRA_HOST}"',
        'VITE_HIBIRA_PORT' => '"${HIBIRA_PORT}"',
        'VITE_HIBIRA_SCHEME' => '"${HIBIRA_SCHEME}"',
    ];

    public function handle(Filesystem $files): int
    {
        foreach (['.env', '.env.example'] as $name) {
            $path = base_path($name);
            if (! $files->exists($path)) {
                continue;
            }
            $added = $this->writeEnv($files, $path);
            $this->components->info($added === 0 ? "$name already set up." : "Added $added Hibira settings to $name.");
        }

        if (! $this->option('no-echo')) {
            $this->writeEcho($files);
        }

        if (! $files->exists(base_path('routes/channels.php'))) {
            $this->components->warn('No routes/channels.php yet. Run `php artisan install:broadcasting --without-reverb --without-node` to add it, then run this command again.');
        }

        $this->newLine();
        $this->line('  Next:');
        $this->line('  1. Copy the app ID, key and secret from your app page on <comment>https://hibira.io/portal</comment> into .env.');
        $this->line('  2. <comment>npm install laravel-echo pusher-js</comment> and import "./echo" in resources/js/app.js.');
        $this->line('  3. <comment>php artisan hibira:check</comment> to test the connection.');

        return self::SUCCESS;
    }

    protected function writeEnv(Filesystem $files, string $path): int
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
            $lines[] = 'BROADCAST_CONNECTION=hibira';
        }

        foreach (self::ENV as $key => $value) {
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

    protected function writeEcho(Filesystem $files): void
    {
        $target = resource_path('js/echo.js');

        if ($files->exists($target) && ! $this->option('force')) {
            if (! str_contains($files->get($target), 'VITE_HIBIRA_APP_KEY')) {
                $this->components->warn('resources/js/echo.js exists and does not use Hibira. Run with --force to replace it.');
            }

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(__DIR__.'/../../stubs/echo.js', $target);
        $this->components->info('Wrote resources/js/echo.js.');
    }
}
