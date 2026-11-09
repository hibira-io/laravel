# Changelog

## 0.4.0

- Waiting rooms: the `hibira.waiting:ROOM` middleware sends visitors
  without a pass to the hosted waiting page and lets them in with the pass
  they bring back (checked locally, kept in a cookie until it expires).
- `Hibira::waitingRoom('presale')` (and the `WaitingRoom` facade) creates,
  pauses, opens, closes, admits, posts a notice to, lists and deletes
  rooms, and verifies passes.
- `php artisan hibira:waiting-room` does the same from the command line.
- New settings: `HIBIRA_WAITING_ROOMS`, `HIBIRA_PREVIOUS_APP_SECRET`,
  `HIBIRA_WAITING_ROOM_HOST`.

## 0.3.0

- `pusher/pusher-push-notifications` is now optional (`suggest`), so
  `composer require hibira-io/laravel` works on a fresh Laravel 13 app,
  whose Guzzle 8 the Beams SDK does not support yet.
- **Upgrading:** if you send push notifications, run
  `composer require pusher/pusher-push-notifications -W`. Without it,
  `hibira:check` and `app('hibira.push')` say what to install.

## 0.2.1

- README: cover image and screenshots.

## 0.2.0

- Laravel AI SDK streaming: `$agent->broadcastOnQueue($prompt,
  AgentChannel::user($user))` streams to a private channel this package
  authorizes (`hibira-ai.user.{id}`, and `hibira-ai.conversation.{id}` for
  the conversation's owner). Read it with `useAgentStream()` from
  `@hibira-io/react` or `@hibira-io/vue` 0.2.
- The `hibira` connection splits Laravel AI stream events bigger than
  `HIBIRA_MAX_EVENT_BYTES` (4096, the Free plan's limit) into
  `hibira:chunk` events, so tool results no longer fail with "Event data
  exceeds the maximum size". `HIBIRA_CHUNK_EVENTS=all` does it for every
  event, `off` turns it off. The `echo.js` stub joins chunks for Echo
  listeners.
- `php artisan hibira:doctor` checks credentials, the broadcast connection,
  the auth route, the queue, Echo, chunking and the Laravel AI setup, and
  says how to fix each problem.
- Laravel Boost guidelines and a `hibira-realtime` skill in
  `resources/boost`, picked up by `php artisan boost:install`.

## 0.1.0

First release.

- `hibira` broadcast connection for Laravel 5.6 to 13, with no entry
  needed in `config/broadcasting.php`.
- `Hibira` and `HibiraPush` facades; `Pusher\Pusher` and
  `Pusher\PushNotifications\PushNotifications` resolve to clients pointed at
  Hibira.
- `hibira-push` notification channel and `HibiraPushMessage`.
- Optional push user token route and verified webhook route
  (`WebhookReceived` event).
- `hibira:install` and `hibira:check` commands.
