import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/*
 * The dev server host, pinned on purpose.
 *
 * Left unset, Vite binds to `localhost`, which on macOS (Node 17+) resolves to ::1. The address the
 * server reports back is then an IPv6 literal, laravel-vite-plugin writes `http://[::1]:5173` into
 * public/hot, and the browser loads every module and the HMR socket from that origin.
 *
 * CSP's host-source grammar has no way to express an IPv6 literal — a browser discards the whole
 * token — so no Content-Security-Policy can ever admit that origin. Binding to an IPv4 loopback
 * literal keeps the dev server reachable under the same strict policy that ships.
 *
 * VITE_DEV_HOST is here for the developer who needs to reach Vite from another device; anything that
 * is not a plain IPv4 host will reintroduce the CSP problem above.
 */
const devHost = process.env.VITE_DEV_HOST || '127.0.0.1';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        host: devHost,
        // Pinned as well, so the HMR socket cannot drift to a different host than the modules.
        hmr: {
            host: devHost,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
