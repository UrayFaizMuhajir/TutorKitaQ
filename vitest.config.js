import { defineConfig } from 'vitest/config';

// Konfigurasi terpisah dari vite.config.js agar tes JS tidak memuat plugin Laravel/Tailwind.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
    },
});
