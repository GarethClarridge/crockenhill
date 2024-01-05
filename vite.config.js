import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import viteCompression from 'vite-plugin-compression';

export default defineConfig({
    server: {
        host: 'localhost',
        watch: {
            usePolling: true,
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/main.scss', 'resources/js/app.js'],
            refresh: true,
        }),
        viteCompression()
    ],
});