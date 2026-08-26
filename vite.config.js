import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// No Bunny/Google Fonts CDN plugin here — design-system.md §1.4 requires
// self-hosted fonts only. Inter ships via @fontsource-variable/inter,
// imported directly in resources/css/app.css.
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // resources/css/filament/admin/theme.css and its Vite entry
                // (previously here) were removed 26 Aug 2026: admin/vendor
                // Filament panels no longer carry any custom theme CSS —
                // see AdminPanelProvider's doc block ("SEVENTH change") for
                // the full record of that reversal.
            ],
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
