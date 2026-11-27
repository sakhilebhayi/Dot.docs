import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y, openWelcomePack } from '../support/helpers';

/** axe on every main screen (serious and critical violations fail, since EA-8). */
for (const [label, path] of [
    ['files', '/files'],
    ['profile', '/user/profile'],
    ['slash commands', '/settings/slash-commands'],
    ['api tokens', '/user/api-tokens'],
    ['bin', '/documents/bin'],
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

test('an error page and the home page have their landmarks and no serious violations (EA-8)', async ({ page }) => {
    const response = await page.goto('/this-page-does-not-exist');
    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoCriticalA11y(page, '404 page');

    await page.goto('/');
    await expect(page.getByRole('main')).toHaveCount(1);
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
});
