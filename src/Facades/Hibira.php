<?php

namespace Hibira\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The Channels HTTP API client (pusher-php-server), pointed at Hibira.
 *
 * @method static object trigger(string|array $channels, string $event, mixed $data, array $params = [], bool $alreadyEncoded = false)
 * @method static object triggerBatch(array $batch = [], bool $alreadyEncoded = false)
 * @method static object getChannelInfo(string $channel, array $params = [])
 * @method static object getChannels(array $params = [])
 * @method static object getPresenceUsers(string $channel)
 * @method static mixed get(string $path, array $params = [], bool $associative = false)
 * @method static object terminateUserConnections(string $userId)
 *
 * @see \Pusher\Pusher
 */
class Hibira extends Facade
{
    /** A Hibira waiting room: Hibira::waitingRoom('presale')->pause(). */
    public static function waitingRoom(string $name): \Hibira\Laravel\WaitingRooms\WaitingRoom
    {
        return static::$app->make('hibira.waiting-rooms')->room($name);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'hibira';
    }
}
