# Changelog

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
