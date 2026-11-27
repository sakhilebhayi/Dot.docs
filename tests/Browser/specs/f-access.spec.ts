import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y } from '../support/helpers';

/** R2-3b: someone a link cannot open asks; the owner sees it on Sharing and decides (declined here, so the outsider stays out for the other specs). */
test('an outsider asks for access and the owner decides on the Sharing page', async ({ browser }) => {
    // Its own private document: the welcome pack may already be published by an earlier spec, and a published
    // document sends an outsider to its public page instead.
    const owner = await as(browser, users.owner);
    await owner.goto('/files');
    await owner.getByRole('button', { name: 'New document' }).click();
    await owner.locator('#files-new-doc').fill('Audit figures');
    await owner.keyboard.press('Enter');
    await expect(owner).toHaveURL(/\/edit$/);
    const editUrl = owner.url();
    const uuid = editUrl.split('/').slice(-2)[0];

    const outsider = await as(browser, users.outsider);
    const denied = await outsider.goto('/o/' + uuid);
    expect(denied?.status()).toBe(403);
    await expect(outsider.getByRole('heading', { name: 'You cannot open this document yet' })).toBeVisible();
    await expectNoCriticalA11y(outsider, 'request access');
    await outsider.locator('#access-message').fill('I am on the audit team.');
    await outsider.getByRole('button', { name: 'Ask for access' }).click();
    await expect(outsider.getByText('Asked. The owner has been told')).toBeVisible();

    await owner.goto(editUrl.replace(/\/edit$/, '/share'));
    const asks = owner.locator('#access-requests');
    await expect(asks).toContainText('I am on the audit team.');
    await expectNoCriticalA11y(owner, 'share page with a request');
    await asks.getByRole('button', { name: /Decline/ }).click();
    await expect(owner.getByText('Declined. Nothing was sent')).toBeVisible();

    const still = await outsider.goto('/o/' + uuid);
    expect(still?.status()).toBe(403);
});
