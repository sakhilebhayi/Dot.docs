import { expect, test } from '@playwright/test';
import { stateFile, users } from '../support/env';
import { openWelcomePack, typeAtEnd, waitSaved } from '../support/helpers';

test.use({ storageState: stateFile(users.owner) });

test('on a phone the editor opens, saves and the page never scrolls sideways', async ({ page }) => {
    await openWelcomePack(page);
    await typeAtEnd(page, ' From a phone.');
    await waitSaved(page);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});
