import { expect, type Browser, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { password, stateFile } from './env';

/** A page signed in as `email` (the session global setup saved). */
export async function as(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({ storageState: stateFile(email) });
    return context.newPage();
}

/** Signs in through the form - only for the tests about signing in itself. */
export async function signInThroughTheForm(page: Page, email: string, secret = password): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(secret);
    await page.keyboard.press('Enter');
}

/** Opens the seeded document in the editor and waits for the editor to be ready. */
export async function openWelcomePack(page: Page): Promise<string> {
    await page.goto('/documents');
    await page.getByRole('link', { name: /Welcome pack/ }).first().click();
    await expect(page).toHaveURL(/\/edit$/);
    await expect(page.locator('.ProseMirror')).toBeVisible();
    return page.url();
}

/** Puts the caret at the end of the document's last paragraph and types. */
export async function typeAtEnd(page: Page, text: string): Promise<void> {
    const last = page.locator('.ProseMirror p').last();
    await last.click();
    await page.keyboard.press('End');
    await page.keyboard.type(text);
}

/** Waits for the status strip to say Saved (autosave debounce + round trip). */
export async function waitSaved(page: Page): Promise<void> {
    await expect(page.locator('.doc-status')).toContainText('Saved', { timeout: 15_000 });
}

/**
 * axe on the current page. Fails on CRITICAL violations; serious ones are attached to the report and counted, so
 * EA-8's accessibility work can drive them to zero and then tighten this to 'serious'.
 */
export async function expectNoCriticalA11y(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    const critical = results.violations.filter((v) => v.impact === 'critical');
    const serious = results.violations.filter((v) => v.impact === 'serious');
    if (serious.length > 0) {
        console.log(`[a11y] ${label}: ${serious.length} serious - ${serious.map((v) => v.id).join(', ')}`);
    }
    expect(critical.map((v) => `${v.id}: ${v.help} (${v.nodes.length})`), `${label}: critical axe violations`).toEqual([]);
}
