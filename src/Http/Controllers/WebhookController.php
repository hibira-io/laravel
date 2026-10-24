<?php

namespace Hibira\Laravel\Http\Controllers;

use Hibira\Laravel\Events\WebhookReceived;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Receives Hibira webhooks, checks X-Pusher-Signature and dispatches one
 * WebhookReceived per event.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $config = config('hibira');

        abort_unless(static::verify($request, (string) $config['key'], (string) $config['secret']), 401, 'Invalid webhook signature');

        $body = json_decode($request->getContent(), true);
        abort_unless(is_array($body) && is_array($body['events'] ?? null), 400, 'Invalid webhook body');

        $timeMs = (int) ($body['time_ms'] ?? 0);
        foreach ($body['events'] as $event) {
            if (! is_array($event) || ! is_string($event['name'] ?? null)) {
                continue;
            }
            event(new WebhookReceived($event['name'], $event['channel'] ?? null, $event, $timeMs));
        }

        return new Response('', 200);
    }

    /**
     * X-Pusher-Signature is signed with the app's current secret. While a
     * rotated-out secret is in its grace period, X-Hibira-Previous-Signature
     * is signed with the old one, so an app that still has the old secret
     * keeps accepting webhooks until it is redeployed.
     */
    public static function verify(Request $request, string $key, string $secret): bool
    {
        if ($key === '' || $secret === '' || ! hash_equals($key, (string) $request->header('X-Pusher-Key'))) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        foreach (['X-Pusher-Signature', 'X-Hibira-Previous-Signature'] as $header) {
            $signature = $request->header($header);
            if (is_string($signature) && $signature !== '' && hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}
