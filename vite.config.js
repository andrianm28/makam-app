import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// No Bunny/Google Fonts CDN plugin here — design-system.md §1.4 requires
// self-hosted fonts only. Inter ships via @fontsource-variable/inter,
// imported directly in resources/css/app.css.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
