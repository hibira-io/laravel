# hibira-io/laravel

[![ci](https://github.com/hibira-io/laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/hibira-io/laravel/actions/workflows/ci.yml)

Laravel broadcasting, push notifications, webhooks and Laravel AI SDK
streaming for [Hibira](https://hibira.io), set up with one `composer require`.

Hibira speaks the Pusher Channels protocol, so Laravel's built-in `pusher`
broadcaster already works with it. This package saves the wiring: a
`hibira` broadcast connection that points at the right host, a Laravel Echo
client, a notification channel for iOS, Android and browser push, a
verified webhook route, Laravel AI SDK agent streams that fit every plan's
event size limit, and `hibira:doctor` to check the whole setup.

Supports Laravel 5.6 to 13 (PHP 7.1.3+), with pusher-php-server 3 to 7.

## Install

```sh
composer require hibira-io/laravel
php artisan hibira:install
```

`hibira:install` adds the `HIBIRA_*` settings to `.env` and `.env.example`,
sets `BROADCAST_CONNECTION=hibira` (`BROADCAST_DRIVER` before Laravel 11)
and writes `resources/js/echo.js`, for Vite or, in older apps, Laravel Mix.
It never overwrites a value you already have.

Copy the app ID, key and secret from your app page on
[hibira.io/portal](https://hibira.io/portal) (the free plan needs no card):

```ini
BROADCAST_CONNECTION=hibira
HIBIRA_APP_ID=your-app-id
HIBIRA_APP_KEY=your-app-key
HIBIRA_APP_SECRET=your-app-secret
HIBIRA_HOST=ws.hibira.io
```

Then check them:

```sh
php artisan hibira:check
```

If your project has no `routes/channels.php` yet, run
`php artisan install:broadcasting --without-reverb --without-node` first
(Laravel 11 and later; older apps ship the file and register it with
`App\Providers\BroadcastServiceProvider`).

## Broadcasting

Nothing changes in your events. Anything that implements `ShouldBroadcast`
goes to Hibira:

```php
class OrderShipped implements ShouldBroadcast
{
    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->order->id)];
    }
}
```

Private and presence channels are authorized in `routes/channels.php` as
usual, through `/broadcasting/auth`.

To trigger without an event class, use the `Hibira` facade (the
pusher-php-server client, already pointed at Hibira):

```php
use Hibira\Laravel\Facades\Hibira;

Hibira::trigger('orders', 'order-shipped', ['id' => 1024]);
Hibira::getChannelInfo('presence-room.1', ['info' => 'user_count']);
```

Type-hinting `Pusher\Pusher` gives you the same client.

## Laravel Echo

`hibira:install` writes this `resources/js/echo.js` (with
`process.env.MIX_HIBIRA_*` instead in Laravel Mix apps):

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_HIBIRA_APP_KEY,
    cluster: 'hkg', // required by pusher-js, unused once wsHost is set
    wsHost: import.meta.env.VITE_HIBIRA_HOST ?? 'ws.hibira.io',
    wsPort: 443,
    wssPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
});
```

Install the client packages and import it from `resources/js/app.js`:

```sh
npm install laravel-echo pusher-js
```

```js
import './echo';

Echo.private(`orders.${orderId}`).listen('OrderShipped', (e) => console.log(e.order));
```

For presence rooms with thousands of members, use a `presence-large-*`
channel and [`@hibira-io/large-presence`](https://github.com/hibira-io/large-presence-js).

## Push notifications

Enable push on your app page (Push notifications), upload your APNs key or
FCM service account there, and add:

```ini
HIBIRA_PUSH_INSTANCE_ID=your-instance-id
HIBIRA_PUSH_SECRET_KEY=your-secret-key
```

Send with a notification:

```php
use Hibira\Laravel\Push\HibiraPushMessage;

class OrderShippedNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['hibira-push'];
    }

    public function toHibiraPush($notifiable): HibiraPushMessage
    {
        return HibiraPushMessage::create('Order shipped', "Order #{$this->order->id} is on its way.")
            ->link(route('orders.show', $this->order)) // opened when a browser notification is clicked
            ->data(['order_id' => $this->order->id])
            ->sound();
    }
}

$user->notify(new OrderShippedNotification($order));
```

The notification goes to every device signed in as that user. The user ID is
the model's key, or what `routeNotificationForHibiraPush()` returns if your
model defines it. To send to an interest instead, add
`->interests('news')`.

`HibiraPushMessage` fills APNs, FCM and Web Push from one title and body.
`->only('apns', 'fcm')` limits the platforms, and `->apns([...])`,
`->fcm([...])` and `->web([...])` merge platform-specific fields on top.

Or publish directly with the `HibiraPush` facade (the
pusher-push-notifications client, pointed at Hibira):

```php
use Hibira\Laravel\Facades\HibiraPush;

HibiraPush::publishToInterests(['news'], HibiraPushMessage::create('Hello')->toArray());
```

### Signed-in users

The web, Android and iOS SDKs link a device to a user with `setUserId`,
which fetches a token from your backend. Turn on the token route:

```ini
HIBIRA_PUSH_TOKEN_ROUTE=push/user-token
```

It is behind the `web` and `auth` middleware (change
`hibira.push.token_middleware` for an API guard) and refuses a `user_id`
that is not the signed-in user's. Point the SDKs at it, for example in the
browser with [`@hibira-io/push-web`](https://github.com/hibira-io/hibira-push-web):

```js
await push.setUserId(String(userId), new TokenProvider({ url: '/push/user-token' }));
```

## Webhooks

Turn on the webhook route:

```ini
HIBIRA_WEBHOOK_PATH=hibira/webhook
```

Add `https://your-app.example/hibira/webhook` on your app page (Webhooks).
Each request's signature is checked with your app secret, then every event
is dispatched as `Hibira\Laravel\Events\WebhookReceived`:

```php
use Hibira\Laravel\Events\WebhookReceived;

Event::listen(function (WebhookReceived $webhook) {
    if ($webhook->name === 'member_removed') {
        Room::markOffline($webhook->channel, $webhook->userId());
    }
});
```

Events: `channel_occupied`, `channel_vacated`, `member_added`,
`member_removed`, `client_event`, `cache_miss` and `subscription_count`.
The route skips CSRF because it is outside the `web` middleware group.
After you rotate the app secret, webhooks keep verifying with the old
secret until you deploy the new one.

## Laravel AI SDK agents

With the [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`, Laravel 12+ and PHP 8.3+),
queue an agent and stream its events to the signed-in user's private
channel:

```php
use App\Ai\Agents\SupportAgent;
use Hibira\Laravel\Ai\AgentChannel;

Route::post('/chat', function (Request $request) {
    (new SupportAgent)
        ->forUser($request->user())
        ->broadcastOnQueue($request->string('message'), AgentChannel::user($request->user()));

    return response()->noContent();
})->middleware('auth');
```

`AgentChannel::user($user)` is `private-hibira-ai.user.{id}`, and
`AgentChannel::conversation($conversationId)` is
`private-hibira-ai.conversation.{id}`. The package authorizes both: a user
may join only their own channel, and a conversation's channel only when
the conversation was stored for them. You don't add them to
`routes/channels.php` (set `HIBIRA_AI_CHANNELS=false` to authorize them
yourself). Keep a queue worker running.

To finish a turn that paused for tool approvals, broadcast the rest on the
same channel:

```php
(new SupportAgent)
    ->continue($conversationId, as: $user)
    ->broadcastOnQueue(Decisions::from($request->input('decisions')), AgentChannel::user($user));
```

In the browser, [`@hibira-io/react`](https://github.com/hibira-io/react)
and [`@hibira-io/vue`](https://github.com/hibira-io/vue) (0.2 and later)
put the stream back together, on the Echo connection you already have:

```jsx
import { HibiraProvider, useAgentStream } from '@hibira-io/react';

<HibiraProvider client={window.Echo.connector.pusher}>
    <Chat />
</HibiraProvider>

function Chat() {
    const { text, status, toolCalls, approvals, error } = useAgentStream(`private-hibira-ai.user.${user.id}`, { rewind: true });
    // status: idle, streaming, awaiting_approval, done or error
}
```

`rewind: true` replays what the page missed after a reload or a dropped
connection when channel history is on for the app (Hobby plans and up).

### Events over 4 KB

The Free plan accepts events of up to 4 KB (10 KB on Hobby and Starter),
and a tool result is often bigger: the Channels API refuses it and the
browser never sees it. The `hibira` connection splits any Laravel AI
stream event over `HIBIRA_MAX_EVENT_BYTES` into `hibira:chunk` events
that `useAgentStream()`, and the `echo.js` stub for Echo listeners, join
back together. You don't need `#[WithoutBroadcasting(ToolResult::class)]`
to stay under the limit.

```ini
HIBIRA_MAX_EVENT_BYTES=4096   # your plan's per-event limit; higher means fewer chunks
HIBIRA_CHUNK_EVENTS=ai        # ai (default), all (every event), or off
```

Each chunk counts as a message. With `all`, your own big events are split
too, and every listener needs the joiner: re-run `php artisan
hibira:install --force`, or copy the end of
[`stubs/echo.js`](stubs/echo.js) into your `echo.js`.

### Laravel Boost

The package ships [Laravel Boost](https://github.com/laravel/boost)
guidelines and a `hibira-realtime` skill. Run `php artisan boost:install`
(or `boost:update`) and your AI agent knows how to broadcast, stream
agents and check the setup.

## Checking the setup

```sh
php artisan hibira:doctor
```

It checks the credentials (as `hibira:check` does), the broadcast
connection, the `/broadcasting/auth` route, the queue, `VITE_HIBIRA_APP_KEY`,
the Echo file, chunking and, with `laravel/ai`, the agent channels,
agents that skip broadcasting tool results and your `@hibira-io/react` /
`vue` versions. Each problem comes with the fix. `--offline` skips the
API call.

## Configuration

`client_options` needs pusher-php-server 5 or later. Publish
`config/hibira.php` to change more:

```sh
php artisan vendor:publish --tag=hibira-config
```

| Setting | `.env` | Default |
|---|---|---|
| `host` | `HIBIRA_HOST` | `ws.hibira.io`; use the host your app page shows, such as `ws-edge.hibira.io` for apps on Pro and up that use edge delivery, or `ws-<cluster>.hibira.io` for apps in another region |
| `port`, `scheme` | `HIBIRA_PORT`, `HIBIRA_SCHEME` | `443`, `https` |
| `options`, `client_options` | | Extra pusher-php-server and Guzzle options |
| `push.endpoint` | `HIBIRA_PUSH_ENDPOINT` | `https://ws.hibira.io` |
| `push.token_route` | `HIBIRA_PUSH_TOKEN_ROUTE` | off |
| `webhooks.path` | `HIBIRA_WEBHOOK_PATH` | off |
| `chunking.max_bytes` | `HIBIRA_MAX_EVENT_BYTES` | `4096`, the Free plan's per-event limit |
| `chunking.events` | `HIBIRA_CHUNK_EVENTS` | `ai`; or `all`, `off` |
| `ai.channels` | `HIBIRA_AI_CHANNELS` | `true`: authorize the `AgentChannel` channels |

The `hibira` broadcast connection needs no entry in
`config/broadcasting.php`. To broadcast to a second Hibira app, add a
connection with its own credentials; any key you leave out comes from
`config/hibira.php`:

```php
'connections' => [
    'hibira-tokyo' => [
        'driver' => 'hibira',
        'app_id' => env('HIBIRA_TOKYO_APP_ID'),
        'key' => env('HIBIRA_TOKYO_APP_KEY'),
        'secret' => env('HIBIRA_TOKYO_APP_SECRET'),
        'host' => 'ws-tyo.hibira.io',
    ],
],
```

## Coming from the pusher broadcaster

Your events, channel names and `routes/channels.php` stay the same. Run
`hibira:install`, fill in the `HIBIRA_*` values and switch your Echo
options to the `VITE_HIBIRA_*` ones (or let `hibira:install --force`
replace `echo.js`). You can remove the `PUSHER_*` settings afterwards.

Without the package, the built-in `pusher` connection works too, as long as
`config/broadcasting.php` passes `host`, `port`, `scheme` and `useTLS` in its
options (configs from older Laravel versions often pass only the cluster,
which sends traffic to pusher.com):

```ini
PUSHER_HOST=ws.hibira.io
PUSHER_PORT=443
PUSHER_SCHEME=https
```

## Examples

A full Laravel chat app using this package is in
[hibira-io/examples](https://github.com/hibira-io/examples/tree/main/laravel-chat).

## Development

```sh
composer install
composer test
```

## License

[MIT](LICENSE)
