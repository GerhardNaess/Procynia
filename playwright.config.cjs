// CommonJS format so Playwright can load this config on Node 18.x without ESM restrictions.
const { defineConfig, devices } = require('@playwright/test');

// One output folder per run, so a later run never wipes the evidence of an earlier failure
// (KTF-4, KTF-5). Set once here; the worker processes inherit it. Old folders in test-results/ are
// removed by hand once a failure has been looked into.
process.env.PW_RUN_ID ??= new Date().toISOString().replace(/[:.]/g, '-');

module.exports = defineConfig({
    testDir: './tests/e2e',

    // Reasonable timeout for a Laravel/Inertia app on a local machine
    timeout: 30_000,

    // No retries in first layer — flaky tests must be fixed, not retried
    retries: 0,

    // Sequential execution: avoids database race conditions with a shared test database
    workers: 1,

    // Only failing tests keep their output (trace, screenshots, error context) …
    outputDir: `test-results/run-${process.env.PW_RUN_ID}`,
    preserveOutput: 'failures-only',

    // … and the server's logs for the same minutes (global-teardown.js).
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

    // Writes the nginx and PHP-FPM logs into each failed test's output folder.
    globalTeardown: './tests/e2e/global-teardown.js',
});
