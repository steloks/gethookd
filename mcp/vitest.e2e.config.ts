import { defineConfig } from 'vitest/config';

// End-to-end tests against the real Laravel API: need Postgres and Redis running.
export default defineConfig({
  test: {
    include: ['test/e2e/**/*.e2e.test.ts'],
    testTimeout: 30_000,
    fileParallelism: false,
  },
});
