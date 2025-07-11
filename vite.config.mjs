import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import viteCompression from 'vite-plugin-compression';

const buildId = Date.now().toString(36);

export default defineConfig({
    server: {
        host: 'localhost',
        watch: {
            usePolling: true,
        },
    },
    build: {
        rollupOptions: {
            output: {
                entryFileNames: `assets/[name]-[hash]-${buildId}.js`,
                chunkFileNames: `assets/[name]-[hash]-${buildId}.js`,
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                        return `assets/[name]-[hash]-${buildId}.css`;
                    }
                    return `assets/[name]-[hash]-${buildId}[extname]`;
                }
            }
        }
    },
    plugins: [
        laravel({
            input: ['resources/css/app.scss', 'resources/js/app.js'],
            refresh: true,
        }),
        viteCompression()
    ],
});