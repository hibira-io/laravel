import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const port = Number(import.meta.env.VITE_HIBIRA_PORT ?? 443);

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_HIBIRA_APP_KEY,
    // pusher-js requires a cluster, but does not use it once wsHost is set.
    cluster: 'hkg',
    wsHost: import.meta.env.VITE_HIBIRA_HOST ?? 'ws.hibira.io',
    wsPort: port,
    wssPort: port,
    forceTLS: (import.meta.env.VITE_HIBIRA_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

// Events bigger than your plan's limit (4 KB on Free), such as Laravel AI SDK
// tool results, arrive as "hibira:chunk" pieces. Join them and hand the
// original event to your Echo listeners.
const subscribe = window.Echo.connector.pusher.subscribe.bind(window.Echo.connector.pusher);
window.Echo.connector.pusher.subscribe = (name) => {
    const channel = subscribe(name);
    if (!channel.hibiraChunks) {
        channel.hibiraChunks = {};
        channel.bind('hibira:chunk', (chunk) => {
            const parts = channel.hibiraChunks[chunk.id] || (channel.hibiraChunks[chunk.id] = []);
            if (parts.done) return;
            parts[chunk.index] = chunk.data;
            if (parts.filter((part) => part !== undefined).length < chunk.count) return;
            const json = parts.join('');
            channel.hibiraChunks[chunk.id] = { done: true };
            channel.emit(chunk.event, JSON.parse(json));
        });
    }
    return channel;
};
