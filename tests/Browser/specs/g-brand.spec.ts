import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y } from '../support/helpers';

/**
 * R2-3c: an admin fills in the brand and saves AT ONCE (no pause after typing): every value must be stored. The
 * fields used a 400 ms live delay that let a quick Save go first and store the old values.
 */
test('the brand kit saves what was typed, even when Save is clicked straight away', async ({ browser }) => {
    const page = await as(browser, users.owner);
    page.setDefaultNavigationTimeout(90000);
    await page.goto('/brand', { waitUntil: 'domcontentloaded' });
    await expectNoCriticalA11y(page, 'brand page');

    await page.locator('#brand-name').fill('Northwind Advisory');
    await page.locator('#brand-headingColour').fill('#123456');
    await page.locator('#brand-address').fill('12 Florida Road, Durban');
    await page.locator('#brand-disclaimer').fill('For the named recipient only.');
    await page.getByRole('button', { name: 'Save the brand' }).first().click();
    await expect(page.getByText('Saved. Documents that use')).toBeVisible();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#brand-name')).toHaveValue('Northwind Advisory');
    await expect(page.locator('#brand-headingColour')).toHaveValue('#123456');
    await expect(page.locator('#brand-address')).toHaveValue('12 Florida Road, Durban');
    await expect(page.locator('#brand-disclaimer')).toHaveValue('For the named recipient only.');
    await expect(page.locator('.brand-sheet')).toContainText('12 Florida Road, Durban');
});
