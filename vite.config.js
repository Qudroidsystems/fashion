import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// The admin UI uses the prebuilt Velzon theme in public/theme, so a Vite build
// is optional. Keep this for any new bundled assets.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
