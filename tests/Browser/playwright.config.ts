import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import { appEnv, baseURL, browserRoot, databaseFile, dataDir, port, repoRoot } from './support/env';

// The server starts before global setup and waits for /up, which needs the database file to exist.
fs.mkdirSync(dataDir, { recursive: true });
if (!fs.existsSync(databaseFile)) {
    fs.writeFileSync(databaseFile, '');
}

/**
 * Dot.docs browser suite (EA-7): the real journeys in a real browser, plus axe on every main screen.
 * Run: `npm run test:browser` (needs `npm run build` first).
 */
export default defineConfig({
    testDir: path.join(browserRoot, 'specs'),
    outputDir: path.join(browserRoot, 'test-results'),
    globalSetup: path.join(browserRoot, 'global-setup.ts'),
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 90_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { outputFolder: path.join(browserRoot, 'playwright-report'), open: 'never' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 15_000,
    },
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port} --no-reload`,
        cwd: repoRoot,
        url: `${baseURL}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: { ...appEnv(), PHP_CLI_SERVER_WORKERS: '4' },
    },
    projects: [
        { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } }, testIgnore: /phone\.spec\.ts/ },
        { name: 'phone-chromium', use: { ...devices['Pixel 7'] }, testMatch: /phone\.spec\.ts/ },
    ],
});
