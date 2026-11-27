import { test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y, openWelcomePack } from '../support/helpers';

/** axe on every main screen (critical violations fail; serious ones are listed for EA-8). */
for (const [label, path] of [
    ['files', '/files'],
    ['profile', '/user/profile'],
    ['slash commands', '/settings/slash-commands'],
    ['api tokens', '/user/api-tokens'],
] as const) {
    test(`${label} has no critical accessibility violations`, async ({ browser }) => {
        const page = await as(browser, users.owner);
        await page.goto(path);
        await expectNoCriticalA11y(page, label);
    });
}

test('document settings and history have no critical accessibility violations', async ({ browser }) => {
    const page = await as(browser, users.owner);
    const base = (await openWelcomePack(page)).replace(/\/edit$/, '');
    await page.goto(`${base}/settings`);
    await expectNoCriticalA11y(page, 'document settings');
    await page.goto(`${base}/history`);
    await expectNoCriticalA11y(page, 'version history');
});

test('the signed-out home page has no critical accessibility violations', async ({ page }) => {
    await page.goto('/');
    await expectNoCriticalA11y(page, 'home');
});
