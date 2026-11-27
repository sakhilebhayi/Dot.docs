import { expect, type Page, test } from '@playwright/test';
import { users } from '../support/env';
import { as, openWelcomePack, typeAtEnd, waitSaved } from '../support/helpers';

/**
 * R2-1 / S-06: the Content-Security-Policy is enforced. Every main screen, with its scripts running (editor typing,
 * palette, assistant, sheets), must cause no violation - one would mean a feature silently broken for real people.
 */
function watch(page: Page): string[] {
    const violations: string[] = [];
    page.on('console', (message) => {
        if (/Content Security Policy|Refused to (execute|load|apply)/i.test(message.text())) {
            violations.push(`${page.url()} :: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => violations.push(`${page.url()} :: pageerror ${error.message}`));
    return violations;
}

test('no screen breaks under the enforced Content-Security-Policy (R2-1)', async ({ browser }) => {
    const page = await as(browser, users.owner);
    const violations = watch(page);

    const header = (await page.request.get('/')).headers()['content-security-policy'] ?? '';
    expect(header, 'the policy is enforced').toContain("'nonce-");

    for (const path of ['/dashboard', '/documents', '/files', '/templates', '/user/profile', '/user/api-tokens']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
    }

    const editUrl = await openWelcomePack(page);
    await typeAtEnd(page, ' CSP check.');
    await waitSaved(page);
    await page.keyboard.press('ControlOrMeta+K');
    await expect(page.locator('.dotdoc-panel-input')).toBeVisible();
    await page.keyboard.press('Escape');
    await page.keyboard.press('ControlOrMeta+Shift+K');
    await expect(page.locator('#ai-command')).toBeVisible();
    await page.keyboard.press('Escape');

    for (const suffix of ['/share', '/settings', '/history']) {
        await page.goto(editUrl.replace(/\/edit$/, suffix));
        await page.waitForLoadState('networkidle');
    }

    await page.goto('/documents');
    await page.getByRole('button', { name: 'New document' }).first().click();
    await expect(page.getByRole('dialog', { name: 'New document' })).toBeVisible();
    await page.keyboard.press('Escape');

    const guest = await (await browser.newContext()).newPage();
    const guestViolations = watch(guest);
    for (const path of ['/', '/this-page-does-not-exist']) {
        await guest.goto(path);
        await guest.waitForLoadState('networkidle');
    }

    expect([...violations, ...guestViolations]).toEqual([]);
});
