import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoSeriousA11y, openWelcomePack } from '../support/helpers';

/**
 * R2-9: the accessibility gate on the screens the earlier specs did not reach (the template gallery, the comments
 * sidebar, document webhooks with an endpoint listed, the team page), and the command palette's focus handling.
 * The brand page and the sharing page with a published link are covered in g-brand and h-wide-a11y.
 */

test('the command palette keeps Tab inside it and hands the caret back to the document on Escape', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    const editor = page.locator('.ProseMirror');
    const input = page.locator('.dotdoc-panel-input');

    await page.locator('.ProseMirror p').last().click();
    await page.keyboard.press('End');
    // Where the caret is, read off the page's own selection: the document is left as it is for other specs.
    const caret = () =>
        page.evaluate(() => {
            // TipTap parks the editor on its own DOM node.
            const ed = (document.querySelector('.ProseMirror') as HTMLElement & { editor?: { state: { selection: { from: number; to: number } } } }).editor;
            return ed ? { from: ed.state.selection.from, to: ed.state.selection.to } : null;
        });
    const before = await caret();
    expect(before?.from ?? 0).toBeGreaterThan(1);

    await page.keyboard.press('ControlOrMeta+K');
    await expect(input).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(input).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(input).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(page.locator('.dotdoc-overlay')).toHaveCount(0);
    await expect(editor).toBeFocused();
    // The caret is where it was, not at the top of the document.
    await expect.poll(caret).toEqual(before);

    // A click on the backdrop closes it the same way.
    await page.keyboard.press('ControlOrMeta+K');
    await expect(input).toBeFocused();
    await page.locator('.dotdoc-overlay').click({ position: { x: 5, y: 5 } });
    await expect(page.locator('.dotdoc-overlay')).toHaveCount(0);
    await expect(editor).toBeFocused();
});

test('the template gallery has no serious violations while open', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await page.goto('/documents');
    await page.getByRole('button', { name: 'From a template' }).click();
    await expect(page.getByRole('dialog', { name: 'Start from a template' })).toBeVisible();
    await expectNoSeriousA11y(page, 'template gallery');
});

test('the editor with the comments sidebar open has no serious violations', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    await page.getByRole('button', { name: 'More', exact: true }).click();
    await page.getByRole('button', { name: 'Show comments' }).click();
    await expect(page.locator('.editor-side')).toBeVisible();
    await expectNoSeriousA11y(page, 'comments sidebar');
});

test('document webhooks with an endpoint waiting for approval have no serious violations', async ({ browser }) => {
    const page = await as(browser, users.owner);
    const base = (await openWelcomePack(page)).replace(/\/edit$/, '');
    await page.goto(`${base}/settings`);
    // A pending endpoint sends nothing until approved, and the test removes it again.
    await page.locator('#hook-url').fill('https://hooks.example.test/e2e');
    await page.getByRole('button', { name: 'Add the endpoint' }).click();
    await expect(page.getByRole('button', { name: 'Approve it' })).toBeVisible();
    await expectNoSeriousA11y(page, 'webhooks');

    await page.getByRole('button', { name: 'I have copied it' }).click();
    await page.getByRole('button', { name: 'Reject it' }).click();
    await page.getByLabel('Why are you rejecting it?').fill('Browser suite clean-up.');
    await page.getByRole('button', { name: 'Reject it' }).click();
    const endpoints = page.getByRole('region', { name: 'Endpoints' });
    await expect(endpoints).toContainText('hooks.example.test');
    await expectNoSeriousA11y(page, 'webhooks with a reviewed endpoint');

    page.once('dialog', (d) => d.accept());
    await endpoints.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('No endpoint is listening to this document.')).toBeVisible();
});

test('the team page has no serious violations', async ({ browser }) => {
    const page = await as(browser, users.owner);
    // The seed makes the owner's team first, on a fresh database.
    await page.goto('/teams/1');
    await expect(page.getByLabel('Team Name')).toHaveValue('E2E Company');
    await expectNoSeriousA11y(page, 'team page');
    // No document on this page: the skip link says so.
    await expect(page.getByRole('link', { name: 'Skip to the content' })).toHaveCount(1);
});
