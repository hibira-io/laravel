<?php

namespace Hibira\Laravel\Console;

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

        $this->components->twoColumnDetail('Host', $config['scheme'].'://'.$config['host'].':'.$config['port']);
        $this->components->twoColumnDetail('Broadcast connection', $connection === 'hibira' ? 'hibira' : "<fg=yellow>$connection</> (set BROADCAST_CONNECTION=hibira)");

        try {
            $channels = app('hibira')->getChannels();
        } catch (Throwable $e) {
            $this->components->error('Realtime: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Realtime', '<fg=green>OK</>, '.count((array) ($channels->channels ?? [])).' occupied channels');

        if (blank($config['push']['instance_id'] ?? null)) {
            $this->components->twoColumnDetail('Push notifications', '<fg=gray>not configured</>');
        } else {
            try {
                app('hibira.push')->generateToken('hibira-check');
                $this->components->twoColumnDetail('Push notifications', '<fg=green>configured</> ('.$config['push']['endpoint'].')');
            } catch (Throwable $e) {
                $this->components->error('Push notifications: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
