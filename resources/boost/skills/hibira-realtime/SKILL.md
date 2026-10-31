---
name: hibira-realtime
description: Use when adding or debugging realtime features on Hibira in a Laravel app with hibira-io/laravel — broadcasting events, Laravel Echo, private or presence channels, streaming Laravel AI SDK agents to the browser (broadcastOnQueue, useAgentStream), push notifications or Hibira webhooks.
license: MIT
metadata:
  author: hibira
---

# Realtime on Hibira with hibira-io/laravel

## First, check the setup

Run `php artisan hibira:doctor` (add `--offline` without network). It checks credentials, the broadcast connection, the `/broadcasting/auth` route, the queue, the Echo file, chunking and, with `laravel/ai`, the agent channels and the `@hibira-io/react` / `@hibira-io/vue` versions. Fix every ✗ before writing code.

## Broadcasting an event

1. `BROADCAST_CONNECTION=hibira` in `.env`.
2. An event implementing `ShouldBroadcast`, with `broadcastOn()` returning a `PrivateChannel` or `PresenceChannel` for user data.
3. The channel authorized in `routes/channels.php`.
4. In the browser, `Echo.private('orders.1').listen('OrderShipped', ...)` with `resources/js/echo.js` from `php artisan hibira:install`.

## Streaming a Laravel AI SDK agent

1. Queue the agent and broadcast its events to a package-authorized channel:
   `$agent->broadcastOnQueue($prompt, AgentChannel::user($request->user()))` (`Hibira\Laravel\Ai\AgentChannel`).
2. Keep `php artisan queue:work` running.
3. Frontend: `npm install @hibira-io/react` (or `@hibira-io/vue`), wrap the app in `<HibiraProvider client={window.Echo.connector.pusher}>` (Vue: `createHibira({ client: window.Echo.connector.pusher })`), then
   `useAgentStream(`private-hibira-ai.user.${user.id}`, { rewind: true })` gives `text`, `reasoning`, `status` (`idle`, `streaming`, `awaiting_approval`, `done`, `error`), `toolCalls`, `approvals` and `run.parts` in display order.
4. Without React or Vue, listen with Echo to the event names `.text_delta`, `.tool_result`, `.stream_end`, ... (leading dot: they have no namespace). Large ones arrive as `hibira:chunk` pieces; the `echo.js` stub joins them.

## Size limits

Free plan events are at most 4 KB, Hobby and Starter 10 KB. With the default `HIBIRA_CHUNK_EVENTS=ai`, Laravel AI events over `HIBIRA_MAX_EVENT_BYTES` (4096) are split automatically. Each chunk counts as a message. For your own large events, prefer sending an ID and fetching the data over HTTP.

## Gotchas

- `broadcastOnQueue()` with the `sync` queue runs the whole agent inside the HTTP request.
- Channel names in JavaScript carry the prefix: `AgentChannel::user($user)` is `private-hibira-ai.user.{id}`.
- `rewind` needs channel history switched on for the app in the Hibira portal (Hobby and up).
- Never expose `HIBIRA_APP_SECRET` to the browser or commit it.
