## Hibira (hibira-io/laravel)

Hibira is a hosted WebSocket service that speaks the Pusher Channels protocol. This package adds the `hibira` broadcast connection, Laravel Echo setup, push notifications and webhooks.

- Broadcast with `BROADCAST_CONNECTION=hibira`; there is no entry to add in `config/broadcasting.php`. Events implementing `ShouldBroadcast` and `Broadcast::on(...)` go to Hibira unchanged.
- Credentials live in `.env` as `HIBIRA_APP_ID`, `HIBIRA_APP_KEY`, `HIBIRA_APP_SECRET`, `HIBIRA_HOST`. Never write the app secret into code, config defaults or client-side files; only `VITE_HIBIRA_APP_KEY` and `VITE_HIBIRA_HOST` may reach the browser.
- Run `php artisan hibira:doctor` after changing broadcasting, queues, Echo or AI streaming, and fix what it reports. `php artisan hibira:check` checks only the credentials.
- Use private (`PrivateChannel`) or presence channels for anything user-specific, authorized in `routes/channels.php`.
- The Free plan refuses events over 4 KB. Laravel AI SDK stream events over the limit are split into `hibira:chunk` events automatically (`HIBIRA_CHUNK_EVENTS=ai`); do not add `#[WithoutBroadcasting(ToolResult::class)]` just to stay under the limit. For other large payloads, broadcast an ID and let the client fetch the data, or set `HIBIRA_CHUNK_EVENTS=all` (then the browser needs the joiner in `resources/js/echo.js`).
- Broadcasting runs on the queue: keep a worker running (`php artisan queue:work`) and do not use the `sync` queue in production.

### Laravel AI SDK streaming

@verbatim
<code-snippet name="Stream an agent to the signed-in user" lang="php">
use Hibira\Laravel\Ai\AgentChannel;

Route::post('/chat', function (Request $request) {
    (new SupportAgent)
        ->forUser($request->user())
        ->broadcastOnQueue($request->string('message'), AgentChannel::user($request->user()));

    return response()->noContent();
});
</code-snippet>
@endverbatim

- `AgentChannel::user($user)` (`private-hibira-ai.user.{id}`) and `AgentChannel::conversation($conversationId)` (`private-hibira-ai.conversation.{id}`) are authorized by the package; do not register them again in `routes/channels.php`.
- To resume a turn after tool approvals, broadcast the continuation on the same channel: `$agent->continue($conversationId, as: $user)->broadcastOnQueue(Decisions::from(...), AgentChannel::user($user))`.
- In React or Vue, read the stream with `useAgentStream()` from `@hibira-io/react` or `@hibira-io/vue` (0.2 or later) instead of hand-written Echo listeners:

@verbatim
<code-snippet name="Render the agent stream in React" lang="jsx">
import { HibiraProvider, useAgentStream } from '@hibira-io/react';

// <HibiraProvider client={window.Echo.connector.pusher}> around the app
const { text, status, toolCalls, approvals, error } = useAgentStream(`private-hibira-ai.user.${user.id}`, { rewind: true });
</code-snippet>
@endverbatim

- `rewind: true` replays missed events after a reload or reconnect when channel history is on for the app (Hobby plans and up); on Free it is ignored.
