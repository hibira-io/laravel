import Pusher from 'pusher-js';
import type { Channel } from 'pusher-js';

// Events bigger than your plan's limit (4 KB on Free), such as Laravel AI SDK
// tool results, arrive as "hibira:chunk" pieces. This client joins them and
// hands the original event to your useEcho() listeners.
type Chunk = { id: string; event: string; index: number; count: number; data: string };

const joining = new WeakSet<Channel>();

class HibiraPusher extends Pusher {
    subscribe(name: string): Channel {
        const channel = super.subscribe(name);
        if (!joining.has(channel)) {
            joining.add(channel);
            const parts: Record<string, Array<string | undefined> | 'done'> = {};
            channel.bind('hibira:chunk', (chunk: Chunk) => {
                const seen = parts[chunk.id] ?? (parts[chunk.id] = []);
                if (seen === 'done') return;
                seen[chunk.index] = chunk.data;
                if (seen.filter((part) => part !== undefined).length < chunk.count) return;
                parts[chunk.id] = 'done';
                channel.emit(chunk.event, JSON.parse(seen.join('')));
            });
        }
        return channel;
    }
}

const port = Number(import.meta.env.VITE_HIBIRA_PORT ?? 443);

// Pass to configureEcho() from @laravel/echo-react or @laravel/echo-vue.
export const hibiraEcho = {
    broadcaster: 'pusher' as const,
    Pusher: HibiraPusher,
    key: import.meta.env.VITE_HIBIRA_APP_KEY as string,
    // pusher-js requires a cluster, but does not use it once wsHost is set.
    cluster: 'hkg',
    wsHost: (import.meta.env.VITE_HIBIRA_HOST as string | undefined) ?? 'ws.hibira.io',
    wsPort: port,
    wssPort: port,
    forceTLS: (import.meta.env.VITE_HIBIRA_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'] as Array<'ws' | 'wss'>,
};
