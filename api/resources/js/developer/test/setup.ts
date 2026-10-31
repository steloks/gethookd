import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// Same output on every machine: the page formats in the browser's language and time zone, so
// pin both (a German host locale used to turn "1,000" into "1.000").
process.env.TZ = 'UTC';
Object.defineProperty(navigator, 'language', { value: 'en-US', configurable: true });

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});
