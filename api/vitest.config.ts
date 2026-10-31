import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// The developer page's tests (Laravel's own tests are Pest).
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/js/developer/test/setup.ts'],
    },
});
