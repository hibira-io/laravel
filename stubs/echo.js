import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const port = Number(import.meta.env.VITE_HIBIRA_PORT ?? 443);

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_HIBIRA_APP_KEY,
    // pusher-js requires a cluster, but does not use it once wsHost is set.
    cluster: 'hibira',
    wsHost: import.meta.env.VITE_HIBIRA_HOST ?? 'ws.hibira.io',
    wsPort: port,
    wssPort: port,
    forceTLS: (import.meta.env.VITE_HIBIRA_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
