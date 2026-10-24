<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime (Channels)
    |--------------------------------------------------------------------------
    |
    | The app ID, key and secret from your app page on https://hibira.io.
    | The host is the WebSocket host shown there: ws.hibira.io for the
    | default cluster, or ws-<cluster>.hibira.io for apps in another region.
    |
    */

    'app_id' => env('HIBIRA_APP_ID'),
    'key' => env('HIBIRA_APP_KEY'),
    'secret' => env('HIBIRA_APP_SECRET'),

    'host' => env('HIBIRA_HOST', 'ws.hibira.io'),
    'port' => (int) env('HIBIRA_PORT', 443),
    'scheme' => env('HIBIRA_SCHEME', 'https'),

    // Extra pusher-php-server options and Guzzle client options.
    'options' => [],
    'client_options' => [],

    /*
    |--------------------------------------------------------------------------
    | Push notifications
    |--------------------------------------------------------------------------
    |
    | From the app page → Push notifications. The endpoint is the push API
    | base URL shown there.
    |
    | token_route serves Beams user tokens to your signed-in users' devices
    | (for setUserId in the web, Android and iOS SDKs). It is off until you
    | set a path such as "push/user-token".
    |
    */

    'push' => [
        'instance_id' => env('HIBIRA_PUSH_INSTANCE_ID'),
        'secret_key' => env('HIBIRA_PUSH_SECRET_KEY'),
        'endpoint' => env('HIBIRA_PUSH_ENDPOINT', 'https://ws.hibira.io'),

        'token_route' => env('HIBIRA_PUSH_TOKEN_ROUTE'),
        'token_middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Set a path such as "hibira/webhook" to receive webhooks there. Each
    | event is dispatched as Hibira\Laravel\Events\WebhookReceived after the
    | signature is checked. Add the full URL on the app page → Webhooks.
    |
    */

    'webhooks' => [
        'path' => env('HIBIRA_WEBHOOK_PATH'),
        'middleware' => [],
    ],

];
