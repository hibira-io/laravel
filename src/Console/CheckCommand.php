<?php

namespace Hibira\Laravel\Console;

use Exception;
use Hibira\Laravel\Exceptions\MissingCredentials;
use Hibira\Laravel\Hibira;
use Hibira\Laravel\Support\OriginProbe;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class CheckCommand extends Command
{
    protected $signature = 'hibira:check';

    protected $description = 'Check the Hibira credentials in .env by calling the Channels API';

    public function handle(): int
    {
        $config = config('hibira');
        $connection = config('broadcasting.default');

        $this->line(sprintf('  Host: %s://%s:%s', $config['scheme'], $config['host'], $config['port']));
        if ($connection === 'hibira') {
            $this->line('  Broadcast connection: hibira');
        } else {
            $this->warn("  Broadcast connection: $connection (set BROADCAST_CONNECTION=hibira)");
        }

        $missing = app(Hibira::class)->missingRealtimeCredentials();
        if ($missing !== []) {
            $this->error('  Realtime: '.MissingCredentials::realtime($missing[0])->getMessage());

            return 1;
        }

        try {
            // get() exists in every pusher-php-server version; 3.x and 4.x
            // return false or a status array instead of throwing.
            $result = app('hibira')->get('/channels');
            if ($result === false || (is_array($result) && ($result['status'] ?? 200) !== 200)) {
                throw new Exception('the Channels API refused the request. Check HIBIRA_APP_ID, HIBIRA_APP_KEY, HIBIRA_APP_SECRET and HIBIRA_HOST.');
            }
        } catch (Throwable $e) {
            $this->error('  Realtime: '.$e->getMessage());

            return 1;
        }
        $this->info('  Realtime: OK');

        if (! $this->checkOrigins($config)) {
            return 1;
        }

        if (blank($config['push']['instance_id'] ?? null)) {
            $this->line('  Push notifications: not configured');

            return 0;
        }

        try {
            app('hibira.push')->generateToken('hibira-check');
        } catch (Throwable $e) {
            $this->error('  Push notifications: '.$e->getMessage());

            return 1;
        }
        $this->info('  Push notifications: configured ('.$config['push']['endpoint'].')');

        return 0;
    }

    /**
     * Connects as a browser on APP_URL would. A page whose origin is not in
     * the app's Allowed origins can't connect, which is the most common
     * reason a page stays on "connecting".
     */
    protected function checkOrigins(array $config): bool
    {
        $origin = OriginProbe::originOf(config('app.url'));
        if ($origin === null) {
            $this->line('  Allowed origins: not checked (set APP_URL to the address you open the app at)');

            return true;
        }

        $probe = $this->laravel->bound('hibira.origin-probe')
            ? $this->laravel->make('hibira.origin-probe')
            : new OriginProbe($config['host'], $config['port'], $config['scheme'], $config['key']);

        $twin = OriginProbe::loopbackTwin($origin);
        foreach (array_filter([$origin, $twin]) as $candidate) {
            try {
                $result = $probe->check($candidate);
            } catch (RuntimeException $e) {
                $this->warn('  Allowed origins: not checked ('.$e->getMessage().')');

                return true;
            }

            if ($result['allowed']) {
                $this->info("  Allowed origins: $candidate OK");
            } elseif ($candidate === $origin) {
                $this->error("  Browser connection from $candidate: ".$result['message']);
                if ($result['code'] === 4009) {
                    $this->line("    Add $candidate (APP_URL) to Allowed origins on the app's page at hibira.io/portal.");
                } elseif ($result['code'] === 4001) {
                    $this->line('    Check HIBIRA_APP_KEY: browsers connect with the key, the server side with the ID and secret.');
                }

                return false;
            } elseif ($result['code'] === 4009) {
                $this->warn("  Allowed origins: $candidate can't connect");
                $serve = strpos($candidate, '127.0.0.1') !== false ? ' (php artisan serve prints that address)' : '';
                $this->line("    Browsers treat it as a different site from $origin. Add it too if you open the app at $candidate$serve.");
            } else {
                $this->warn("  Browser connection from $candidate: ".$result['message']);
            }
        }

        return true;
    }
}
