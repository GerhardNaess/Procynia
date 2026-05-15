// CommonJS format so Playwright can load this config on Node 18.x without ESM restrictions.
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/e2e',

    // Reasonable timeout for a Laravel/Inertia app on a local machine
    timeout: 30_000,

    // No retries in first layer — flaky tests must be fixed, not retried
    retries: 0,

    // Sequential execution: avoids database race conditions with a shared test database
    workers: 1,

    reporter: [['line'], ['html', { open: 'never' }]],

    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080',
        trace: 'on-first-retry',
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],

    // Seeds test users before the test suite runs
    globalSetup: './tests/e2e/global-setup.js',
});
