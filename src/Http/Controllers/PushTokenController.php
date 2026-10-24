<?php

namespace Hibira\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Returns a Beams user token for the signed-in user, for setUserId in the
 * web, Android and iOS SDKs. The SDKs pass ?user_id=, which must be the
 * signed-in user's ID.
 */
class PushTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $userId = method_exists($user, 'routeNotificationFor')
            ? $user->routeNotificationFor('hibira-push')
            : null;
        $userId = (string) ($userId ?? $user->getAuthIdentifier());

        abort_unless(hash_equals($userId, (string) $request->query('user_id', '')), 401);

        return new JsonResponse(app('hibira.push')->generateToken($userId));
    }
}
