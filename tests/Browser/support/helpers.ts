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
    // `.ProseMirror > p`: the document's own paragraphs. In Pages view the header and footer bands are <p>s too, inside
    // the page-boundary widgets, and `.ProseMirror p` last was the footer band - a click there put the caret at 1.
    const last = page.locator('.ProseMirror > p').last();
    await last.click();
    await page.keyboard.press('End');
    await page.keyboard.type(text);
}

/** Waits for the status strip to say Saved (autosave debounce + round trip). */
export async function waitSaved(page: Page): Promise<void> {
    await expect(page.locator('.doc-status')).toContainText('Saved', { timeout: 15_000 });
}

/**
 * axe on the current page. Fails on SERIOUS and CRITICAL violations (EA-8; EA-7 failed on critical only).
 *
 * Waits for entrance animations first: axe reads colours with the ancestors' opacity, so a page caught mid
 * fade-in reports text paler than it is.
 *
 * One known exclusion, owned elsewhere: the shared Dot UI account button (`.dh-account__btn`, from dot-ui/,
 * identical on every platform and guarded by DotUiDriftTest) shows the person's initials while its accessible
 * name is "Account menu: <name>" - axe's label-content-name-mismatch. The fix belongs in dot-ui for the whole
 * fleet; listed as a follow-up in docs/programme/ENTERPRISE_AUDIT.md.
 */
export async function expectNoSeriousA11y(page: Page, label: string): Promise<void> {
    await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'), null, { timeout: 5_000 }).catch(() => {});
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    const failing = results.violations
        .filter((v) => v.impact === 'serious' || v.impact === 'critical')
        .map((v) => ({
            ...v,
            nodes: v.nodes.filter((n) => !(v.id === 'label-content-name-mismatch' && n.target.join(' ').includes('.dh-account__btn'))),
        }))
        .filter((v) => v.nodes.length > 0);
    expect(
        failing.map((v) => `${v.id}: ${v.help} -> ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`),
        `${label}: serious or critical axe violations`,
    ).toEqual([]);
}

/** @deprecated EA-7 name; same check, now serious and above. */
export const expectNoCriticalA11y = expectNoSeriousA11y;
