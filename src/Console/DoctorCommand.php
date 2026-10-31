<?php

namespace Hibira\Laravel\Console;

use Hibira\Laravel\HibiraServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class DoctorCommand extends Command
{
    protected $signature = 'hibira:doctor {--offline : Skip the call to the Channels API}';

    protected $description = 'Check the Hibira setup: credentials, broadcasting, queue, Echo and Laravel AI SDK streaming';

    /** @var int */
    protected $failures = 0;

    /** @var int */
    protected $warnings = 0;

    public function handle(): int
    {
        if (! $this->option('offline')) {
            $this->line('Credentials');
            if ($this->call('hibira:check') !== 0) {
                $this->failures++;
            }
            $this->line('');
        }

        $this->line('Broadcasting');
        $this->checkBroadcasting();
        $this->line('');

        $this->line('Browser');
        $this->checkBrowser();

        if (class_exists('Laravel\Ai\Ai')) {
            $this->line('');
            $this->line('Laravel AI SDK');
            $this->checkAgents();
        }

        $this->line('');
        if ($this->failures > 0) {
            $this->error(sprintf('  %d problem(s) and %d warning(s).', $this->failures, $this->warnings));

            return 1;
        }
        $this->info($this->warnings > 0 ? sprintf('  No problems, %d warning(s).', $this->warnings) : '  No problems found.');

        return 0;
    }

    protected function checkBroadcasting(): void
    {
        $default = config('broadcasting.default');
        if ($default === 'hibira' || config("broadcasting.connections.$default.driver") === 'hibira') {
            $this->passCheck("Broadcast connection: $default");
        } else {
            $this->failCheck("Broadcast connection: $default", 'Set BROADCAST_CONNECTION=hibira (BROADCAST_DRIVER before Laravel 11).');
        }

        if ($this->authRouteExists()) {
            $this->passCheck('Private channel auth route: /broadcasting/auth');
        } else {
            $this->failCheck(
                'Private channel auth route: missing',
                'Run php artisan install:broadcasting --without-reverb --without-node (Laravel 11+), or call Broadcast::routes() in a service provider.'
            );
        }

        if (file_exists(base_path('routes/channels.php'))) {
            $this->passCheck('routes/channels.php found');
        } else {
            $this->warnCheck('routes/channels.php not found', 'Your own private and presence channels are authorized there.');
        }

        $queue = config('queue.default');
        if ($queue === 'sync') {
            $this->warnCheck(
                'Queue connection: sync',
                'ShouldBroadcast events and broadcastOnQueue() run inside the request. Use database or redis and run php artisan queue:work.'
            );
        } else {
            $this->passCheck("Queue connection: $queue (keep php artisan queue:work running)");
        }

        $chunking = config('hibira.chunking.events', 'ai');
        $max = (int) config('hibira.chunking.max_bytes', 4096);
        if ($chunking === 'off') {
            $this->warnCheck('Chunking: off', 'Events over your plan\'s limit (4 KB on Free) are refused. Set HIBIRA_CHUNK_EVENTS=ai.');
        } else {
            $this->passCheck(sprintf('Chunking: %s events over %s bytes', $chunking === 'all' ? 'all' : 'Laravel AI', $max));
        }
    }

    protected function checkBrowser(): void
    {
        $env = $this->envFile();
        $mix = file_exists(base_path('webpack.mix.js')) && ! file_exists(base_path('vite.config.js'));
        $prefix = $mix ? 'MIX_' : 'VITE_';

        if ($env === null) {
            $this->warnCheck('.env not found', 'Run php artisan hibira:install.');
        } elseif (preg_match('/^'.$prefix.'HIBIRA_APP_KEY=\S+/m', $env)) {
            $this->passCheck($prefix.'HIBIRA_APP_KEY is set');
        } else {
            $this->failCheck($prefix.'HIBIRA_APP_KEY is missing from .env', 'Run php artisan hibira:install, then rebuild your assets.');
        }

        $echo = $this->firstFile(['resources/js/echo.js', 'resources/js/echo.ts', 'resources/js/bootstrap.js']);
        if ($echo === null) {
            $this->warnCheck('No resources/js/echo.js', 'Run php artisan hibira:install to write it.');

            return;
        }

        $source = (string) file_get_contents(base_path($echo));
        if (strpos($source, 'hibira:chunk') !== false || strpos($source, '@hibira-io/') !== false) {
            $this->passCheck("$echo joins chunked events");
        } elseif (config('hibira.chunking.events', 'ai') === 'all') {
            $this->failCheck(
                "$echo does not join chunked events",
                'With HIBIRA_CHUNK_EVENTS=all your listeners need it: run php artisan hibira:install --force, or add the joiner from the README.'
            );
        } else {
            $this->warnCheck(
                "$echo does not join chunked events",
                'Echo listeners miss Laravel AI events over 4 KB. Run php artisan hibira:install --force, or use useAgentStream().'
            );
        }
    }

    protected function checkAgents(): void
    {
        $this->passCheck('laravel/ai installed');

        if (HibiraServiceProvider::agentChannelsEnabled($this->laravel)) {
            $this->passCheck('AgentChannel channels authorized (hibira-ai.user.{id}, hibira-ai.conversation.{id})');
        } else {
            $this->warnCheck('AgentChannel channels: off (HIBIRA_AI_CHANNELS=false)', 'Authorize your agent channels in routes/channels.php.');
        }

        $agents = $this->agentsWithoutBroadcasting();
        foreach ($agents as $agent) {
            $this->warnCheck(
                "$agent skips broadcasting ToolResult or ToolCall",
                'Chunking sends events of any size; you can drop them from #[WithoutBroadcasting] if the browser needs them.'
            );
        }

        $package = $this->packageJson();
        if ($package === null) {
            return;
        }
        $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
        foreach (['@hibira-io/react', '@hibira-io/vue', '@hibira-io/nuxt'] as $name) {
            if (! isset($deps[$name])) {
                continue;
            }
            if (preg_match('/(\d+)\.(\d+)/', $deps[$name], $m) && (int) $m[1] === 0 && (int) $m[2] < 2) {
                $this->failCheck("$name {$deps[$name]} has no useAgentStream()", "Run npm install $name@^0.2.");
            } else {
                $this->passCheck("$name {$deps[$name]}");
            }
        }
    }

    protected function authRouteExists(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (strpos(ltrim($route->uri(), '/'), 'broadcasting/auth') !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> agent classes in app/Ai with #[WithoutBroadcasting(ToolResult|ToolCall)] */
    protected function agentsWithoutBroadcasting(): array
    {
        $found = [];
        foreach (['app/Ai', 'app/Agents'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/#\[WithoutBroadcasting\([^\]]*(ToolResult|ToolCall)/', $source)) {
                    $found[] = $dir.'/'.substr($file->getPathname(), strlen($path) + 1);
                }
            }
        }
        sort($found);

        return $found;
    }

    protected function envFile()
    {
        $path = base_path('.env');

        return file_exists($path) ? (string) file_get_contents($path) : null;
    }

    protected function packageJson()
    {
        $path = base_path('package.json');
        if (! file_exists($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    protected function firstFile(array $paths)
    {
        foreach ($paths as $path) {
            if (file_exists(base_path($path))) {
                return $path;
            }
        }

        return null;
    }

    protected function passCheck(string $message): void
    {
        $this->info("  ✓ $message");
    }

    protected function warnCheck(string $message, string $hint): void
    {
        $this->warnings++;
        $this->warn("  ! $message");
        $this->line("    $hint");
    }

    protected function failCheck(string $message, string $hint): void
    {
        $this->failures++;
        $this->error("  ✗ $message");
        $this->line("    $hint");
    }
}
