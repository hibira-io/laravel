<?php

namespace Hibira\Laravel\Console;

use Hibira\Laravel\WaitingRooms\WaitingRoomException;
use Hibira\Laravel\WaitingRooms\WaitingRooms;
use Illuminate\Console\Command;
use Throwable;

class WaitingRoomCommand extends Command
{
    protected $signature = 'hibira:waiting-room
        {room? : The room name (letters, digits, - and _). Leave out to list rooms}
        {action=status : create, status, open, pause, close, admit, notice or delete}
        {--rate= : Admissions per minute (needed for create)}
        {--title= : Title shown on the waiting page}
        {--starts-at= : Hold the line until this time (RFC 3339, "" clears it)}
        {--return=* : Where admitted visitors may go back to (default: APP_URL)}
        {--count= : For admit: how many queue numbers to let in now}
        {--message= : For notice: the announcement (empty removes it)}
        {--force : Delete without asking}';

    protected $description = 'Create, list, pause, open, close or admit Hibira waiting rooms';

    public function handle(WaitingRooms $rooms): int
    {
        try {
            $name = $this->argument('room');
            if ($name === null) {
                return $this->listRooms($rooms);
            }

            $room = $rooms->room($name);
            $action = $this->argument('action');

            switch ($action) {
                case 'create':
                    if (! $this->option('rate')) {
                        $this->error('  --rate is needed to create a room, for example --rate=300.');

                        return 1;
                    }
                    $returns = $this->option('return') ?: array_filter([rtrim((string) config('app.url'), '/').'/']);
                    $result = $room->create((int) $this->option('rate'), array_filter([
                        'title' => $this->option('title'),
                        'starts_at' => $this->option('starts-at'),
                        'return_urls' => array_values($returns),
                    ], function ($v) {
                        return $v !== null && $v !== [];
                    }));
                    $this->info("  Waiting room \"$name\" is ready.");
                    $this->show($result);
                    $this->line('');
                    $this->line("  Protect a route: Route::middleware('hibira.waiting:$name')");
                    $this->line('  Waiting page:    '.$room->url($returns[0] ?? null));

                    return 0;

                case 'status':
                    $this->show($room->get());
                    $this->line('  Waiting page: '.$room->url());

                    return 0;

                case 'open':
                case 'pause':
                case 'close':
                    $result = $room->{$action}();
                    $this->info("  \"$name\" is now {$result['state']}.");
                    $this->show($result);

                    return 0;

                case 'admit':
                    $count = (int) $this->option('count');
                    if ($count < 1) {
                        $this->error('  --count is needed, for example --count=500.');

                        return 1;
                    }
                    $result = $room->admit($count);
                    $this->info("  Let in up to number {$result['admitted']}.");
                    $this->show($result);

                    return 0;

                case 'notice':
                    $result = $room->notice((string) $this->option('message'));
                    $this->info(($result['notice'] ?? '') === '' ? '  Notice removed.' : '  Notice posted.');

                    return 0;

                case 'delete':
                    if (! $this->option('force') && ! $this->confirm("Delete waiting room \"$name\"? Visitors in it lose their place.")) {
                        return 1;
                    }
                    $room->delete();
                    $this->info("  Deleted \"$name\".");

                    return 0;
            }

            $this->error("  Unknown action \"$action\". Use create, status, open, pause, close, admit, notice or delete.");

            return 1;
        } catch (WaitingRoomException $e) {
            $this->error('  '.($e->notFound() ? 'No such waiting room. Create it with --rate: php artisan hibira:waiting-room '.$this->argument('room').' create --rate=300' : $e->getMessage()));

            return 1;
        } catch (Throwable $e) {
            $this->error('  '.$e->getMessage());

            return 1;
        }
    }

    protected function listRooms(WaitingRooms $rooms): int
    {
        $all = $rooms->all();
        if ($all === null) {
            $this->warn('  This Hibira server cannot list rooms yet. Name one: php artisan hibira:waiting-room presale');

            return 1;
        }
        if ($all === []) {
            $this->line('  No waiting rooms yet. Create one: php artisan hibira:waiting-room presale create --rate=300');

            return 0;
        }
        $this->table(['Room', 'Title', 'State', 'Waiting', 'Admitted up to', 'Per minute'], array_map(function ($r) {
            return [$r['room'] ?? '', $r['title'] ?? '', $r['state'] ?? '', $r['waiting'] ?? 0, $r['admitted'] ?? 0, $r['admit_per_minute'] ?? 0];
        }, $all));

        return 0;
    }

    protected function show(array $room): void
    {
        $rows = [];
        foreach (['title', 'state', 'waiting', 'admitted', 'issued', 'admit_per_minute', 'starts_at', 'notice', 'pass_ttl_seconds'] as $key) {
            if (isset($room[$key]) && $room[$key] !== '') {
                $rows[] = [$key, (string) $room[$key]];
            }
        }
        if (! empty($room['return_urls'])) {
            $rows[] = ['return_urls', implode("\n", $room['return_urls'])];
        }
        $this->table(['', $room['room'] ?? ''], $rows);
    }
}
