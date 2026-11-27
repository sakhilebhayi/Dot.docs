import { expect, test } from '@playwright/test';
import { stateFile, users } from '../support/env';
import { expectNoSeriousA11y, openWelcomePack, typeAtEnd, waitSaved } from '../support/helpers';

test.use({ storageState: stateFile(users.owner) });

test('on a phone the editor opens, saves and the page never scrolls sideways', async ({ page }) => {
    await openWelcomePack(page);
    await typeAtEnd(page, ' From a phone.');
    await waitSaved(page);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});

test('on a phone the list, the editor and the sharing page have no serious violations (R2-6)', async ({ page }) => {
    await page.goto('/documents');
    await expectNoSeriousA11y(page, 'documents on a phone');
    const editor = await openWelcomePack(page);
    await expectNoSeriousA11y(page, 'editor on a phone');
    await page.goto(editor.replace(/\/edit$/, '/share'));
    await expectNoSeriousA11y(page, 'sharing on a phone');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});
