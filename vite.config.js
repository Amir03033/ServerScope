import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Fonts are self-hosted from resources/fonts: the build needs no network.
// charts.js (Chart.js) is only loaded on pages with charts, nexus.js
// (Cytoscape.js) only on the NEXUS map, status3d.js only on status pages
// with the 3D hero; three.js itself is a chunk loaded on demand.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/charts.js', 'resources/js/nexus.js', 'resources/js/status3d.js', 'resources/js/globe.js', 'resources/js/pulse.js', 'resources/js/skyline.js', 'resources/fonts/inter-latin-wght.woff2'],
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
