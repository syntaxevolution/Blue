// Echo client setup for Reverb broadcasting.
//
// Imported for its side effects (installing `window.Echo`) from app.ts.
// Private user channels are subscribed via useNotifications() in the
// authenticated layout.
//
// If the Reverb env vars are missing (fresh clone, CI, or a build that
// disables broadcasting), we skip initialising Echo entirely so the
// Pusher client doesn't spam the console with failed connections.

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        Pusher: any;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        Echo: any;
    }
}

const appKey = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;
const host = import.meta.env.VITE_REVERB_HOST as string | undefined;

if (appKey && host) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: appKey,
        wsHost: host,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else if (import.meta.env.DEV) {
    // eslint-disable-next-line no-console
    console.warn('[echo] VITE_REVERB_APP_KEY / VITE_REVERB_HOST not set — real-time notifications disabled.');
}

export default window.Echo ?? null;
