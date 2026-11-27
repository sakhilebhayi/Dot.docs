import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { chromium, expect } from '@playwright/test';
import { appEnv, baseURL, databaseFile, dataDir, password, repoRoot, stateFile, users } from './support/env';

/** A fresh scratch database for every run: emptied, migrated, seeded. Never the app's own database. */
export default async function globalSetup(): Promise<void> {
    fs.mkdirSync(dataDir, { recursive: true });
    fs.writeFileSync(databaseFile, '');
    const env = { ...process.env, ...appEnv() };
    // A clean cache too: the login limiter counts attempts there, and a second run within a minute would inherit them.
    execFileSync('php', ['artisan', 'cache:clear', '--no-interaction'], { cwd: repoRoot, env, stdio: 'inherit' });
    execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: repoRoot, env, stdio: 'inherit' });
    execFileSync('php', ['artisan', 'db:seed', '--class=BrowserTestSeeder', '--force', '--no-interaction'], { cwd: repoRoot, env, stdio: 'inherit' });

    // Each person signs in ONCE and the session is reused by every test: signing in per test trips the app's own
    // login limit (5 a minute), which is the behaviour we want in production.
    const browser = await chromium.launch();
    for (const email of Object.values(users)) {
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        await page.goto('/login');
        await page.getByLabel('Email').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.keyboard.press('Enter');
        await expect(page).not.toHaveURL(/\/login/);
        await context.storageState({ path: stateFile(email) });
        await context.close();
    }
    await browser.close();
}
