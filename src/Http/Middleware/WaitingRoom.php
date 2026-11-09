<?php

namespace Hibira\Laravel\Http\Middleware;

use Closure;
use Hibira\Laravel\WaitingRooms\WaitingRooms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Route::middleware('hibira.waiting:presale') puts a route behind the
 * "presale" waiting room. Visitors without a valid pass are sent to the
 * hosted waiting page; when their number comes up they return with
 * ?hibira_pass=..., which is checked, kept in a cookie until it expires,
 * and taken off the address. The pass is on $request->attributes as
 * "hibira_pass" (a Hibira\Laravel\WaitingRooms\Pass).
 */
class WaitingRoom
{
    /** @var WaitingRooms */
    protected $rooms;

    public function __construct(WaitingRooms $rooms)
    {
        $this->rooms = $rooms;
    }

    public function handle(Request $request, Closure $next, string $room)
    {
        if (! $this->rooms->enabled()) {
            return $next($request);
        }

        $cookie = static::cookieName($room);

        if (is_string($fromQueue = $request->query('hibira_pass'))) {
            if ($pass = $this->rooms->verifyPass($fromQueue, $room)) {
                $response = new RedirectResponse(static::urlWithout($request, 'hibira_pass'));
                $response->headers->setCookie(new Cookie(
                    $cookie, $fromQueue, $pass->expiresAt, '/', null, $request->isSecure(), true, false, 'lax'
                ));
                $response->headers->set('Cache-Control', 'no-store');

                return $response;
            }
        }

        if ($pass = $this->rooms->verifyPass($request->cookies->get($cookie), $room)) {
            $request->attributes->set('hibira_pass', $pass);

            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response()->json([
                'error' => 'waiting_room',
                'message' => 'Join the waiting room first.',
                'waiting_room' => $this->rooms->url($room),
            ], 403, ['Cache-Control' => 'no-store']);
        }

        $return = static::urlWithout($request, 'hibira_pass');
        $response = new RedirectResponse($this->rooms->url($room, $return, static::lang()));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** The cookie that keeps a visitor's pass for a room. */
    public static function cookieName(string $room): string
    {
        return 'hibira_wr_'.$room;
    }

    protected static function urlWithout(Request $request, string $param): string
    {
        $query = $request->query();
        unset($query[$param]);

        return $request->url().($query ? '?'.http_build_query($query) : '');
    }

    /** The app locale as the hosted page names languages (zh_TW → zh-TW). */
    protected static function lang(): ?string
    {
        $locale = app()->getLocale();

        return $locale ? str_replace('_', '-', $locale) : null;
    }
}
