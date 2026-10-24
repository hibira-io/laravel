<?php

namespace Hibira\Laravel\Console;

use Exception;
use Hibira\Laravel\Exceptions\MissingCredentials;
use Hibira\Laravel\Hibira;
use Illuminate\Console\Command;
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
}
