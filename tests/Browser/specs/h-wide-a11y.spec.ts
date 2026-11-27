import { expect, test, type Browser, type Page } from '@playwright/test';
import { baseURL, stateFile, users } from '../support/env';
import { as, expectNoSeriousA11y, openWelcomePack } from '../support/helpers';

/**
 * R2-6: the accessibility gate beyond the screens at rest - with each main dialog open, in night mode, and the
 * control-edge contrast (WCAG 1.4.11) measured rather than assumed. The phone width is in phone.spec.ts.
 */

async function atNight(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({ storageState: stateFile(email), colorScheme: 'dark' });
    await context.addCookies([{ name: 'theme', value: 'dark', url: baseURL }]);
    return context.newPage();
}

test('the new-document sheet has no serious violations while open', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await page.goto('/documents');
    await page.getByRole('button', { name: 'New document' }).first().click();
    await expect(page.getByRole('dialog', { name: 'New document' })).toBeVisible();
    await expectNoSeriousA11y(page, 'new-document sheet');
});

test('the editor with the move sheet and then the command palette open has no serious violations', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    await page.getByRole('button', { name: 'Move', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expectNoSeriousA11y(page, 'move sheet');
    await page.keyboard.press('Escape');

    await page.locator('.ProseMirror').click();
    await page.keyboard.press('ControlOrMeta+K');
    await expect(page.locator('.dotdoc-panel-input')).toBeFocused();
    await expectNoSeriousA11y(page, 'command palette');
});

test('the delete-account confirmation has no serious violations while open', async ({ browser }) => {
    const page = await as(browser, users.colleague);
    await page.goto('/user/profile');
    await page.getByRole('button', { name: 'Delete Account' }).click();
    await expect(page.getByLabel('Password').last()).toBeVisible();
    await expectNoSeriousA11y(page, 'delete-account dialog');
});

test('the main screens have no serious violations at night', async ({ browser }) => {
    const page = await atNight(browser, users.owner);
    for (const path of ['/documents', '/files', '/user/profile', '/documents/bin']) {
        await page.goto(path);
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);
        await expectNoSeriousA11y(page, `${path} at night`);
    }
    const editor = await openWelcomePack(page);
    await expectNoSeriousA11y(page, 'editor at night');
    await page.goto(editor.replace(/\/edit$/, '/share'));
    await expectNoSeriousA11y(page, 'sharing at night');
});

/** Border colour against the ground it sits on, for the first visible element matching each selector. */
async function controlEdges(page: Page): Promise<Record<string, number>> {
    return page.evaluate(() => {
        const rgb = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
        const lum = ([r, g, b]: number[]) => {
            const ch = (v: number) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
            return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
        };
        const ground = (el: Element | null): string => {
            for (let e = el; e; e = e.parentElement) {
                const bg = getComputedStyle(e).backgroundColor;
                const parts = rgb(bg);
                if (parts.length >= 3 && (parts.length === 3 || parts[3] > 0)) return bg;
            }
            return 'rgb(255,255,255)';
        };
        const out: Record<string, number> = {};
        const selectors = {
            button: '.btn:not(.btn-quiet):not(.btn-primary)',
            tag: '.tag:not([aria-pressed="true"])',
            field: 'input.field',
            filter: 'input.dsr-filter',
        };
        for (const [name, selector] of Object.entries(selectors)) {
            const el = [...document.querySelectorAll(selector)].find((e) => (e as HTMLElement).offsetParent !== null);
            if (!el) continue;
            const a = lum(rgb(getComputedStyle(el).borderTopColor));
            const b = lum(rgb(ground(el.parentElement)));
            out[name] = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
        }
        return out;
    });
}

for (const night of [false, true]) {
    test(`button, tag and field edges reach 3:1 against their ground (${night ? 'night' : 'day'})`, async ({ browser }) => {
        const page = night ? await atNight(browser, users.owner) : await as(browser, users.owner);
        // Buttons and filter tags on the documents list; plain fields on the profile page (the shared x-input).
        await page.goto('/documents');
        const edges = await controlEdges(page);
        await page.goto('/user/profile');
        edges.field = (await controlEdges(page)).field;
        if (!('filter' in edges)) throw new Error('the documents filter field was not found');
        expect(Object.keys(edges).sort(), 'each kind of control was found on the page').toEqual(['button', 'field', 'filter', 'tag']);
        for (const [kind, ratio] of Object.entries(edges)) {
            expect(ratio, `${kind} edge contrast ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(3);
        }
    });
}

test('a keyboard user who tabs onto the page sees it focused, a click does not draw the ring', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    const paper = page.locator('.canvas .paper').first();

    await page.locator('.ProseMirror').click();
    await expect.poll(() => paper.evaluate((el) => getComputedStyle(el).outlineStyle)).toBe('none');

    await page.locator('.ProseMirror').blur();
    await page.evaluate(() => document.documentElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true })));
    await page.locator('.ProseMirror').focus();
    await expect.poll(() => paper.evaluate((el) => getComputedStyle(el).outlineStyle)).toBe('solid');
});

test('the sharing page says where the document stands and copies the link in one press', async ({ browser }) => {
    const context = await browser.newContext({ storageState: stateFile(users.owner) });
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL });
    const page = await context.newPage();
    const editor = await openWelcomePack(page);
    await page.goto(editor.replace(/\/edit$/, '/share'));

    const standing = page.getByRole('list', { name: 'Where this document stands' });
    await expect(standing).toBeVisible();
    if (await page.getByRole('button', { name: 'Publish a link' }).isVisible()) {
        await expect(standing).toContainText('Private');
        await page.getByRole('button', { name: 'Publish a link' }).click();
    }
    await expect(standing).toContainText('Link published');

    await page.getByRole('button', { name: 'Copy link' }).click();
    await expect(page.locator('#share-url-said')).toHaveText('Copied');
    const url = await page.locator('#share-url').inputValue();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(url);
    await expectNoSeriousA11y(page, 'sharing with the link published');

    // Leave the shared seed document as other specs expect it.
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Stop publishing' }).click();
    await expect(standing).toContainText('Private');
});
