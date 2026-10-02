import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/sass/app.scss', 'resources/sass/client.scss', 'resources/js/app.js', 'resources/js/address-search.js', 'resources/js/qr-code.js', 'resources/js/copy-registration-message.js', 'resources/js/measurement-chart.js', 'resources/js/form-errors.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
