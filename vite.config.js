import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    // PERFORMANCE — CDN. When ASSET_URL is set (see .env), Vite writes
    // that origin into every built <script>/<link> tag so assets load
    // from the CDN instead of this app's own server. Empty in local
    // dev, so it's a no-op unless you've actually pointed a CDN here.
    base: process.env.ASSET_URL || '/',
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        // PERFORMANCE — minify JS/CSS for production builds (esbuild
        // is Vite's default and is already on, but pinned explicitly
        // here so it can't be quietly turned off later).
        minify: 'esbuild',
        cssMinify: true,
        cssCodeSplit: true,
        // Each Inertia page under resources/js/Pages is already its
        // own chunk (see app.js's import.meta.glob('./Pages/**/*.vue')
        // — that's the "split code into chunks" / route-based lazy
        // loading). This adds VENDOR chunking on top of that: heavy
        // third-party libraries that not every page needs (charts,
        // the PrimeVue component kit, icon sets) get pulled into
        // their own cacheable chunks instead of bloating every page's
        // bundle or getting duplicated across pages.
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-vue': ['vue', '@inertiajs/vue3'],
                    'vendor-primevue': ['primevue/config', 'primevue/toastservice', '@primeuix/themes'],
                    'vendor-charts': ['chart.js'],
                    'vendor-icons': ['@heroicons/vue', '@tabler/icons-vue', 'primeicons'],
                    'vendor-utils': ['sweetalert2', 'axios'],
                },
            },
        },
        // Assets under this size get base64-inlined into the CSS/JS
        // instead of becoming a separate file+request — fewer round
        // trips for the small icons/spinners the UI uses.
        assetsInlineLimit: 4096,
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: '192.168.1.20',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});