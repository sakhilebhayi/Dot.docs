import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y, signInThroughTheForm } from '../support/helpers';

test('an owner signs in through the form and lands on the dashboard', async ({ page }) => {
    await signInThroughTheForm(page, users.colleague);
    await expect(page).toHaveURL(/dashboard/);
});

test('a wrong password is refused with a message', async ({ page }) => {
    await signInThroughTheForm(page, users.colleague, 'not-the-password');
    await expect(page).toHaveURL(/login/);
    await expect(page.getByText(/credentials|do not match/i)).toBeVisible();
});

test('the dashboard lists the seeded document and has no critical accessibility violations', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await page.goto('/dashboard');
    await expect(page.getByText('Welcome pack').first()).toBeVisible();
    await expectNoCriticalA11y(page, 'dashboard');
});
