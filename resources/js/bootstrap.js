import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Realtime only when this build was given a Reverb key (EA-5 / P-09). Without
// one - production today: no socket server on the host - `new Echo()` threw
// and 72 KB of laravel-echo + pusher-js still shipped to every page. The
// condition is a build-time constant, so with no key Vite drops the import and
// the chunk entirely. With a key it loads a moment after the page; the bell and
// the editor already treat a missing window.Echo as "polling only".
// R2-9: and only when the server says realtime is on (<meta name="dot-realtime" content="on">, written by layouts/app
// when the broadcaster is Reverb - the same condition that opens the CSP's connect-src to the socket). A build that
// carries a key but runs where no socket server exists (production, the browser suite) no longer tries one on every page.
const realtimeOn = typeof document !== 'undefined'
    && document.querySelector('meta[name="dot-realtime"]')?.getAttribute('content') === 'on';

if (import.meta.env.VITE_REVERB_APP_KEY && realtimeOn) {
    Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([{ default: Echo }, { default: Pusher }]) => {
            window.Pusher = Pusher;
            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: import.meta.env.VITE_REVERB_APP_KEY,
                wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
                wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
                wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
                forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
            });
            window.Echo.connector.pusher.connection.bind('failed', () => {});
            window.Echo.connector.pusher.connection.bind('unavailable', () => {});
            // Listeners that looked for window.Echo before it existed wait for this.
            window.dispatchEvent(new CustomEvent('echo:ready'));
        })
        .catch(() => {
            // Real-time features unavailable; app continues in polling-only mode
        });
}
