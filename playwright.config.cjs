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
        // With retries off, «on-first-retry» never recorded anything. A failure now keeps its trace,
        // so an intermittent one (KTF-4) can be read afterwards; passing runs keep nothing.
        trace: 'retain-on-failure',
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
