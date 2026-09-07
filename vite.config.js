import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/**
 * Build tooling for the Athar platform.
 *
 * Assets are compiled locally / in CI and the resulting `public/build`
 * directory is uploaded to Hostinger shared hosting. There is no Node
 * runtime on the production host, so nothing here may depend on one.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Every layout loads app.css; the public shell adds public.css.
                'resources/css/app.css',
                'resources/css/public.css',
                // One entry per shell so the dashboard never ships the
                // landing canvas or the two demos (Article 19).  app.js is
                // imported by all three rather than being an entry itself, so
                // the shared core is built once and cached once.
                'resources/js/dashboard.js',
                'resources/js/public.js',
                'resources/js/auth.js',
            ],
            refresh: [
                'resources/views/**',
                'resources/css/**',
                'resources/js/**',
                'routes/**',
                'lang/**',
            ],
        }),
    ],
    build: {
        // Keep the landing-page payload auditable: PRD 13.1 caps the initial
        // dashboard bundle at 250 KB gzipped and the Constitution (Article 19)
        // caps landing-page JS at 40 KB gzipped.
        chunkSizeWarningLimit: 250,
        cssCodeSplit: true,
        sourcemap: false,
        rollupOptions: {
            output: {
                manualChunks: {
                    alpine: ['alpinejs', '@alpinejs/focus'],
                },
            },
        },
    },
    server: {
        host: '127.0.0.1',
        port: 5173,
    },
});
