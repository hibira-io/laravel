<?php

namespace Hibira\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Hibira waiting rooms.
 *
 * @method static \Hibira\Laravel\WaitingRooms\WaitingRoom room(string $name)
 * @method static array|null all()
 * @method static string url(string $room, ?string $returnUrl = null, ?string $lang = null)
 * @method static \Hibira\Laravel\WaitingRooms\Pass|null verifyPass(?string $pass, ?string $room = null)
 * @method static bool enabled()
 *
 * @see \Hibira\Laravel\WaitingRooms\WaitingRooms
 */
class WaitingRoom extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'hibira.waiting-rooms';
    }
}
