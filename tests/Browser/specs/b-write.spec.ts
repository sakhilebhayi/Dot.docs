import { expect, test } from '@playwright/test';
import { users } from '../support/env';
import { as, expectNoCriticalA11y, openWelcomePack, typeAtEnd, waitSaved } from '../support/helpers';

test('a new document is created, typed into, autosaved and still there after a reload', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await page.goto('/documents');
    await expectNoCriticalA11y(page, 'documents index');

    await page.getByRole('button', { name: 'New document' }).first().click();
    await page.locator('#new-doc-name').fill('Quarterly review');
    await page.getByRole('button', { name: 'Create the document' }).click();
    await expect(page).toHaveURL(/\/documents\/[0-9a-f-]+\/edit/);
    await expect(page.locator('.ProseMirror')).toBeVisible();

    await typeAtEnd(page, 'Revenue grew in every region.');
    await waitSaved(page);
    await expectNoCriticalA11y(page, 'editor');

    await page.reload();
    await expect(page.locator('.ProseMirror')).toContainText('Revenue grew in every region.');
});

test('the version figure follows saves without a reload', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    const figure = page.locator('[data-doc-version]');
    const before = Number((await figure.innerText()).replace(/\D/g, ''));

    await typeAtEnd(page, ' One.');
    await waitSaved(page);
    await page.waitForTimeout(1500);
    await typeAtEnd(page, ' Two.');
    await expect.poll(async () => Number((await figure.innerText()).replace(/\D/g, '')), { timeout: 15_000 }).toBeGreaterThanOrEqual(before + 2);
});

test('the assistant says it is a demonstration and adds its answer at the end', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    // Into the document's text: in Pages view the middle of the editor can be a gap between pages, outside the editor.
    await page.locator('.ProseMirror > p').last().click();
    await page.keyboard.press('ControlOrMeta+Shift+K');
    await page.locator('#ai-command').fill('/continue');
    await page.keyboard.press('Enter');
    await expect(page.getByText('Demonstration answer')).toBeVisible();
    await page.getByRole('button', { name: 'Add it at the end' }).click();
    await expect(page.locator('.ProseMirror')).toContainText('Seeded for the browser suite.');
    await expect(page.locator('.ProseMirror')).toContainText('[mock:');
});

test('Escape closes the assistant palette', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    // Into the document's text: in Pages view the middle of the editor can be a gap between pages, outside the editor.
    await page.locator('.ProseMirror > p').last().click();
    await page.keyboard.press('ControlOrMeta+Shift+K');
    await expect(page.locator('#ai-command')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#ai-command')).toBeHidden();
});

test('the New document sheet keeps focus inside and closes with Escape (EA-8)', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await page.goto('/documents');
    await page.getByRole('button', { name: 'New document' }).first().click();
    const sheet = page.getByRole('dialog', { name: 'New document' });
    await expect(sheet).toBeVisible();
    // The trap takes focus once Alpine has initialised the morphed-in sheet.
    await expect.poll(() => page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]'))).toBe(true);

    for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => !!document.activeElement?.closest('[role="dialog"]')), `Tab ${i + 1} stayed inside the sheet`).toBe(true);
    }

    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();
});

test('the command palette tells assistive technology which command is active (EA-8)', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    // Into the document's text: in Pages view the middle of the editor can be a gap between pages, outside the editor.
    await page.locator('.ProseMirror > p').last().click();
    await page.keyboard.press('ControlOrMeta+K');
    const input = page.locator('.dotdoc-panel-input');
    await expect(input).toBeFocused();
    await expect(input).toHaveAttribute('role', 'combobox');
    await page.keyboard.press('ArrowDown');

    const activeId = await input.getAttribute('aria-activedescendant');
    expect(activeId).toBeTruthy();
    await expect(page.locator(`#${activeId}`)).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('[role="option"][aria-selected="true"]')).toHaveCount(1);

    await page.keyboard.press('Escape');
    await expect(input).toBeHidden();
});
