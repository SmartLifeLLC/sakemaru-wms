import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
                'resources/js/handy/incoming-app.js',
                'resources/js/handy/login-app.js',
                'resources/js/handy/home-app.js',
                'resources/js/handy/outgoing-app.js'
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
