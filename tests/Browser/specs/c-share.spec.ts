import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y, openWelcomePack } from '../support/helpers';

test('sharing with someone lets them open it; removing them takes it away', async ({ browser }) => {
    const page = await as(browser, users.owner);
    const editUrl = await openWelcomePack(page);
    await page.goto(editUrl.replace(/\/edit$/, '/share'));
    await expectNoCriticalA11y(page, 'share page');

    await page.locator('#invite-email').fill(users.outsider);
    await page.locator('#invite-role').selectOption('viewer');
    await page.getByRole('button', { name: 'Send the invitation' }).click();
    await expect(page.getByText('Shared. They have been told by email.')).toBeVisible();

    const outsider = await as(browser, users.outsider);
    await outsider.goto(editUrl);
    await expect(outsider.locator('.ProseMirror')).toContainText('Seeded for the browser suite.');

    // EA-8 (U-02): removing someone asks first, and the button names who.
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /^Remove / }).first().click();
    await expect(page.getByRole('button', { name: /^Remove / })).toHaveCount(0);
    const response = await outsider.goto(editUrl);
    expect(response?.status()).toBe(403);
});

test('a password-protected published link asks a visitor for the password; an outsider never gets the editor', async ({ browser }) => {
    const page = await as(browser, users.owner);
    const editUrl = await openWelcomePack(page);
    await page.goto(editUrl.replace(/\/edit$/, '/share'));

    const publish = page.getByRole('button', { name: 'Publish a link' });
    if (await publish.isVisible()) {
        await publish.click();
    }
    await expect(page.locator('#share-url')).toBeVisible();
    await page.locator('#share-password').fill('open-sesame-e2e');
    await page.getByRole('button', { name: 'Save the link settings' }).click();
    await page.waitForLoadState('networkidle');
    const link = await page.locator('#share-url').inputValue();

    const visitor = await (await browser.newContext()).newPage();
    await visitor.goto(link);
    await expect(visitor.getByRole('heading', { name: 'This one is locked' })).toBeVisible();
    await visitor.locator('#password').fill('wrong');
    await visitor.getByRole('button', { name: 'Open the document' }).click();
    await expect(visitor.getByText('Incorrect password.')).toBeVisible();
    await visitor.locator('#password').fill('open-sesame-e2e');
    await visitor.getByRole('button', { name: 'Open the document' }).click();
    await expect(visitor.getByText('Seeded for the browser suite.').first()).toBeVisible();
    await expect(visitor.locator('h1.topbar-title')).toBeVisible();
    await expect(visitor.getByRole('button', { name: 'Print' })).toBeVisible();
    await expectNoCriticalA11y(visitor, 'shared page');

    // EA-1 / S-01: a signed-in outsider holding the link gets the public page, never the editor.
    const outsider = await as(browser, users.outsider);
    const response = await outsider.goto(editUrl);
    expect(response?.status()).toBe(403);
});

test('a document exports as Markdown', async ({ browser }) => {
    const page = await as(browser, users.owner);
    const url = (await openWelcomePack(page)).replace(/\/edit$/, '/export/markdown');
    const response = await page.request.get(url);
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('Seeded for the browser suite.');
});
