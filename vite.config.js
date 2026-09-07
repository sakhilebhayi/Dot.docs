import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/paper.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        chunkSizeWarningLimit: 600,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('@tiptap/')) return 'vendor-tiptap';
                    if (id.includes('laravel-echo') || id.includes('pusher-js')) return 'vendor-echo';
                    if (id.includes('alpinejs')) return 'vendor-alpine';
                },
            },
        },
    },
});
