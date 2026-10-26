import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const port = Number(process.env.MIX_HIBIRA_PORT || 443);

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: process.env.MIX_HIBIRA_APP_KEY,
    // pusher-js requires a cluster, but does not use it once wsHost is set.
    cluster: 'hkg',
    wsHost: process.env.MIX_HIBIRA_HOST || 'ws.hibira.io',
    wsPort: port,
    wssPort: port,
    forceTLS: (process.env.MIX_HIBIRA_SCHEME || 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
