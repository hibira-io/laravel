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
    | Chunking
    |--------------------------------------------------------------------------
    |
    | An event bigger than max_bytes is split into "hibira:chunk" events that
    | useAgentStream() and the echo.js stub join back together. 4096 is the
    | Free plan's per-event limit; raise it to your plan's (10240 on Hobby and
    | Starter) to split less.
    |
    | events: "ai" splits only Laravel AI SDK stream events (tool results are
    | often bigger than 4 KB), "all" every event (your listeners then need the
    | echo.js joiner), "off" none.
    |
    */

    'chunking' => [
        'events' => env('HIBIRA_CHUNK_EVENTS', 'ai'),
        'max_bytes' => (int) env('HIBIRA_MAX_EVENT_BYTES', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel AI SDK
    |--------------------------------------------------------------------------
    |
    | With laravel/ai installed, the private channels of
    | Hibira\Laravel\Ai\AgentChannel (hibira-ai.user.{id} and
    | hibira-ai.conversation.{id}) are authorized for their owners.
    |
    */

    'ai' => [
        'channels' => env('HIBIRA_AI_CHANNELS', true),
    ],

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
