<?php

namespace Hibira\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The push notifications client (pusher-push-notifications), pointed at Hibira.
 *
 * @method static mixed publishToInterests(array $interests, array $publishRequest)
 * @method static mixed publishToUsers(array $userIds, array $publishRequest)
 * @method static mixed deleteUser(string $userId)
 * @method static array generateToken(string $userId)
 *
 * @see \Pusher\PushNotifications\PushNotifications
 */
class HibiraPush extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'hibira.push';
    }
}
