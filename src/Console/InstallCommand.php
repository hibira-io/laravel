<?php

namespace Hibira\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $signature = 'hibira:install
        {--force : Overwrite resources/js/echo.js if it exists}
        {--no-echo : Do not write the Echo client}
        {--from-reverb : Switch an app from Laravel Reverb: replace its Echo client and comment out the REVERB_* settings}';

    protected $description = 'Set up Hibira: .env keys, the hibira broadcast connection and a Laravel Echo client';

    /** The useEcho() packages of the React and Vue starter kits (Laravel 12 and later). */
    public const ECHO_PACKAGES = ['react' => '@laravel/echo-react', 'vue' => '@laravel/echo-vue'];

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
        $fromReverb = (bool) $this->option('from-reverb');
        $usedReverb = $this->usesReverb($files);

        foreach (['.env', '.env.example'] as $name) {
            $path = base_path($name);
            if (! $files->exists($path)) {
                continue;
            }
            $added = $this->writeEnv($files, $path, $bundler);
            $this->info($added === 0 ? "$name already set up." : "Added $added Hibira settings to $name.");
            if ($fromReverb && ($count = $this->commentOutReverb($files, $path)) > 0) {
                $this->info("Commented out $count REVERB_* settings in $name.");
            }
        }

        $framework = $this->option('no-echo') ? null : $this->echoFramework($files);
        if ($framework !== null) {
            $this->writeConfigureEcho($files, $framework);
        } elseif (! $this->option('no-echo')) {
            $this->writeEcho($files, $bundler);
        }

        if (! $files->exists(base_path('routes/channels.php'))) {
            $this->warn('No routes/channels.php yet. Run `php artisan install:broadcasting --without-reverb --without-node` to add it, then run this command again.');
        }

        $this->line('');
        $this->line('  Next:');
        $this->line('  1. Copy the app ID, key and secret from your app page on <comment>https://hibira.io/portal</comment> into .env.');
        if ($framework !== null) {
            $this->line('  2. <comment>npm install '.self::ECHO_PACKAGES[$framework].' pusher-js</comment> if they are not installed yet, then rebuild your assets.');
        } else {
            $this->line('  2. <comment>npm install laravel-echo pusher-js</comment> and import "./echo" in resources/js/app.js.');
        }
        $this->line('  3. <comment>php artisan hibira:check</comment> to test the connection.');

        if ($fromReverb) {
            $this->line('');
            $this->line('  Leaving Reverb:');
            $this->line('  - Stop the <comment>php artisan reverb:start</comment> process (Forge daemon, Supervisor program or container). Hibira runs the WebSocket server for you.');
            $this->line('  - Your events, channel names and routes/channels.php stay as they are.');
            $this->line('  - When nothing else uses it: <comment>composer remove laravel/reverb</comment> and delete config/reverb.php.');
        } elseif ($usedReverb) {
            $this->line('');
            $this->warn('  This app still has Reverb settings. Run php artisan hibira:install --from-reverb to switch the Echo client over too.');
        }

        return 0;
    }

    /** Whether .env or the Echo client still points at Reverb. */
    protected function usesReverb(Filesystem $files): bool
    {
        $env = base_path('.env');
        if ($files->exists($env) && preg_match('/^(VITE_|MIX_)?REVERB_APP_KEY=/m', $files->get($env))) {
            return true;
        }
        foreach ($this->clientFiles() as $path) {
            if ($files->exists($path) && self::isReverbClient($files->get($path))) {
                return true;
            }
        }

        return false;
    }

    public static function isReverbClient(string $source): bool
    {
        return (bool) preg_match('/broadcaster:\s*[\'"]reverb[\'"]/', $source);
    }

    /** @return list<string> */
    protected function clientFiles(): array
    {
        $paths = [resource_path('js/echo.js'), resource_path('js/echo.ts'), resource_path('js/bootstrap.js')];
        foreach (['app.tsx', 'app.jsx', 'app.ts', 'app.js'] as $name) {
            $paths[] = resource_path('js/'.$name);
        }

        return $paths;
    }

    /** Puts "# " in front of REVERB_* and VITE_REVERB_* lines; returns how many. */
    protected function commentOutReverb(Filesystem $files, string $path): int
    {
        $count = 0;
        $env = preg_replace('/^((VITE_|MIX_)?REVERB_[A-Z_]*=)/m', '# $1', $files->get($path), -1, $count);
        if ($count > 0) {
            $files->put($path, $env);
        }

        return $count;
    }

    /**
     * "react" or "vue" when the app configures Echo with configureEcho() from
     * @laravel/echo-react or @laravel/echo-vue (the Laravel 12+ starter kits),
     * null for a classic resources/js/echo.js.
     */
    protected function echoFramework(Filesystem $files)
    {
        $package = base_path('package.json');
        if (! $files->exists($package) || $files->exists(resource_path('js/echo.js'))) {
            return null;
        }
        $json = json_decode($files->get($package), true);
        if (! is_array($json)) {
            return null;
        }
        $deps = array_merge(
            isset($json['dependencies']) ? (array) $json['dependencies'] : [],
            isset($json['devDependencies']) ? (array) $json['devDependencies'] : []
        );
        foreach (self::ECHO_PACKAGES as $framework => $name) {
            if (isset($deps[$name])) {
                return $framework;
            }
        }
        // Same rule as php artisan install:broadcasting: React or Vue apps use the hooks.
        foreach (['react', 'vue'] as $framework) {
            if (isset($deps[$framework]) && $this->appFile($files, $framework) !== null) {
                return $framework;
            }
        }

        return null;
    }

    /** resources/js/app.tsx|jsx for React, app.ts|js for Vue. */
    protected function appFile(Filesystem $files, string $framework)
    {
        $names = $framework === 'react' ? ['app.tsx', 'app.jsx'] : ['app.ts', 'app.js'];
        foreach ($names as $name) {
            if ($files->exists(resource_path('js/'.$name))) {
                return resource_path('js/'.$name);
            }
        }

        return null;
    }

    /**
     * Writes resources/js/hibira-echo.(ts|js) and makes the app's
     * configureEcho() call use it.
     */
    protected function writeConfigureEcho(Filesystem $files, string $framework): void
    {
        $app = $this->appFile($files, $framework);
        if ($app === null) {
            $this->warn('Could not find resources/js/app.'.($framework === 'react' ? 'tsx' : 'ts').'. Pass hibiraEcho from resources/js/hibira-echo to configureEcho() yourself.');
        }
        $typescript = $app === null || substr($app, -3) === '.ts' || substr($app, -4) === '.tsx';
        $target = resource_path('js/hibira-echo.'.($typescript ? 'ts' : 'js'));
        $relative = 'resources/js/'.basename($target);

        if (! $files->exists($target) || $this->option('force') || $this->option('from-reverb')) {
            $files->copy(__DIR__.'/../../stubs/hibira-echo.'.($typescript ? 'ts' : 'js'), $target);
            $this->info("Wrote $relative.");
        }

        if ($app === null) {
            return;
        }

        $source = $files->get($app);
        if (strpos($source, 'hibiraEcho') !== false) {
            return;
        }

        $package = self::ECHO_PACKAGES[$framework];
        $import = "import { hibiraEcho } from './hibira-echo';";
        $call = 'configureEcho(hibiraEcho);';

        if (preg_match('/configureEcho\(\s*\{[^{}]*\}\s*\);?/', $source, $m, PREG_OFFSET_CAPTURE)) {
            // Replace the options install:broadcasting wrote (Reverb or Pusher).
            $source = substr($source, 0, $m[0][1]).$call.substr($source, $m[0][1] + strlen($m[0][0]));
            $source = $this->addImport($source, $import, '/^import[^;]*from\s*[\'"]'.preg_quote($package, '/').'[\'"];?[ \t]*$/m');
        } elseif (strpos($source, 'configureEcho(') !== false) {
            $this->warn('resources/js/'.basename($app).' calls configureEcho() with options this command cannot rewrite. Pass hibiraEcho from ./hibira-echo instead.');

            return;
        } else {
            $source = $this->addImport($source, "import { configureEcho } from '$package';
$import

$call", '/^import .+;[ \t]*$/m');
        }

        $files->put($app, $source);
        $this->info('Configured Echo for Hibira in resources/js/'.basename($app).'.');
    }

    /** Inserts $code after the last line matching $after, or at the top. */
    protected function addImport(string $source, string $code, string $after): string
    {
        if (preg_match_all($after, $source, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            $last = end($matches[0]);
            $at = $last[1] + strlen($last[0]);

            return substr($source, 0, $at)."
".$code.substr($source, $at);
        }

        return $code."
".$source;
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
            $source = $files->get($target);
            $replaceReverb = $this->option('from-reverb') && self::isReverbClient($source);
            if (! $replaceReverb) {
                if (strpos($source, 'HIBIRA_APP_KEY') === false) {
                    $this->warn('resources/js/echo.js exists and does not use Hibira. Run with --force to replace it.');
                }

                return;
            }
        }

        if (! $files->isDirectory(dirname($target))) {
            $files->makeDirectory(dirname($target), 0755, true);
        }
        $files->copy(__DIR__.'/../../stubs/echo'.($bundler === 'mix' ? '.mix' : '').'.js', $target);
        $this->info('Wrote resources/js/echo.js.');
    }
}
