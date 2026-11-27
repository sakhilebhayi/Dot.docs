import { execFileSync } from 'node:child_process';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { appEnv, baseURL, repoRoot, users } from '../support/env';
import { as, expectNoSeriousA11y, openWelcomePack } from '../support/helpers';

/**
 * R2-12: the axe gate (moderate and above since R2-12) on the screens R2-11 found it did not reach - every sign-in
 * page, a refused sign-in, the shared, published and password pages, the claim page and the open menus - and the
 * behaviour the sign-in pages owe a screen reader: the summary takes the focus, every error names its field.
 */

test('the sign-in pages have no violations at rest', async ({ page }) => {
    for (const path of ['/login', '/forgot-password', '/reset-password/e2e-token?email=nobody%40e2e.test']) {
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expectNoSeriousA11y(page, path);
    }
});

/** The refused form: the summary is an alert that has the focus, and the field points at its own message. */
async function expectRefusal(page: Page, fieldId: string, label: string): Promise<void> {
    const summary = page.locator('[data-error-summary]');
    await expect(summary).toBeVisible();
    await expect(summary).toHaveAttribute('role', 'alert');
    await expect(summary).toBeFocused();
    const field = page.locator(`#${fieldId}`);
    await expect(field).toHaveAttribute('aria-invalid', 'true');
    await expect(field).toHaveAttribute('aria-describedby', `${fieldId}-error`);
    await expect(page.locator(`#${fieldId}-error`)).not.toBeEmpty();
    // The summary's line links to the field it is about.
    await expect(summary.locator(`a[href="#${fieldId}"]`).first()).toBeVisible();
    await expectNoSeriousA11y(page, label);
}

test('a refused sign-in says why, ties the message to the field and moves the focus to the summary', async ({ page }) => {
    // An address nobody has: the app's own limit counts attempts per address, and the seeded people sign in elsewhere.
    await page.goto('/login');
    await page.getByLabel('Email').fill('nobody@e2e.test');
    await page.getByLabel('Password', { exact: true }).fill('not-the-password');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/login/);
    await expectRefusal(page, 'email', 'refused sign-in');
    // One Tab from the summary reaches its link, and the link takes the reader to the field.
    await page.keyboard.press('Tab');
    await expect(page.locator('[data-error-summary] a').first()).toBeFocused();
});

test('a refused reset-link request and a refused password confirmation tie the error to the field', async ({ browser, page }) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill('not-an-address');
    // The browser's own check would stop an invalid address before the server sees it.
    await page.locator('form').evaluate((form) => form.setAttribute('novalidate', ''));
    await page.getByRole('button', { name: /Email Password Reset Link/i }).click();
    await expectRefusal(page, 'email', 'refused reset-link request');

    const owner = await as(browser, users.colleague);
    await owner.goto('/user/confirm-password');
    await expect(owner.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoSeriousA11y(owner, 'confirm password');
    await owner.getByLabel('Password').fill('not-the-password');
    await owner.getByRole('button', { name: 'Confirm' }).click();
    await expectRefusal(owner, 'password', 'refused password confirmation');
});

/**
 * Pages the suite cannot reach by navigating, rendered by the app itself and served at their own address with the
 * real assets: the claim page needs a claim in the session (an InfoDot sign-in), the two-factor challenge a person
 * with two-factor on, and registration is closed unless ALLOW_REGISTRATION is set. `errors` is the refused state.
 */
function renderView(view: string, data: string, errors: Record<string, string> | null): string {
    const messages = Object.entries(errors ?? {}).map(([key, text]) => `'${key}' => ['${text.replace(/'/g, "\\'")}']`).join(', ');
    const php = `
        $bag = new Illuminate\\Support\\ViewErrorBag();
        ${errors ? `$bag->put('default', new Illuminate\\Support\\MessageBag([${messages}]));` : ''}
        view()->share('errors', $bag);
        echo view('${view}', ${data})->render();`;
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: repoRoot, env: { ...process.env, ...appEnv() } }).toString();
    return out.slice(out.indexOf('<!DOCTYPE html>'));
}

/** Opens `html` at `path` in a context without the service worker, which would otherwise answer the navigation. */
async function serve(browser: Browser, path: string, html: string): Promise<Page> {
    const page = await (await browser.newContext({ serviceWorkers: 'block' })).newPage();
    await page.route(`${baseURL}${path}`, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: html }));
    await page.goto(path);
    return page;
}

const CLAIM = `['maskedEmail' => 'o***@e2e.test', 'codeLength' => 6, 'minutes' => 10, 'maxAttempts' => 5,
    'verifyUrl' => url('/sso/claim'), 'resendUrl' => url('/sso/claim/resend'), 'cancelUrl' => url('/sso/claim/cancel')]`;

test('the claim page has no violations, with and without a refused code', async ({ browser }) => {
    for (const errors of [null, { code: 'That code is not right. 4 tries left.' }]) {
        const page = await serve(browser, '/sso/claim', renderView('dotsso::claim', CLAIM, errors));
        await expect(page.getByRole('heading', { name: 'Confirm it is you' })).toBeVisible();
        if (errors) {
            await expect(page.locator('#code')).toHaveAttribute('aria-invalid', 'true');
            await expect(page.locator('#code-error')).toHaveText(/not right/);
        }
        await expectNoSeriousA11y(page, `claim page${errors ? ' with a refused code' : ''}`);
    }
});

test('a refused registration and a refused two-factor code tie each error to its field', async ({ browser }) => {
    for (const [view, path, errors, field] of [
        ['auth.register', '/register', null, null],
        ['auth.register', '/register', { email: 'The email has already been taken.', password: 'The password field must be at least 8 characters.' }, 'password'],
        ['auth.two-factor-challenge', '/two-factor-challenge', null, null],
        ['auth.two-factor-challenge', '/two-factor-challenge', { code: 'The provided two factor authentication code was invalid.' }, 'code'],
        ['auth.two-factor-challenge', '/two-factor-challenge', { recovery_code: 'The provided two factor recovery code was invalid.' }, 'recovery_code'],
    ] as const) {
        const page = await serve(browser, path, renderView(view, '[]', errors));
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (field) {
            await expectRefusal(page, field, `${view} refused (${field})`);
            if (view === 'auth.register') {
                await expect(page.locator('#email')).toHaveAttribute('aria-invalid', 'true');
                await expect(page.locator('#name')).not.toHaveAttribute('aria-invalid', 'true');
            }
        } else {
            await expectNoSeriousA11y(page, `${view} at rest`);
        }
    }
});

test('the shared and published pages and their password gates have no violations', async ({ browser }) => {
    // A document of its own, so the other specs' welcome pack is left as they expect it.
    const owner = await as(browser, users.owner);
    await owner.goto('/files');
    await owner.getByRole('button', { name: 'New document' }).click();
    await owner.locator('#files-new-doc').fill('Published for the gate');
    await owner.keyboard.press('Enter');
    await expect(owner).toHaveURL(/\/edit$/);
    await expect(owner.locator('.ProseMirror')).toBeVisible();
    await owner.goto(owner.url().replace(/\/edit$/, '/share'));
    await owner.getByRole('button', { name: 'Publish a link' }).click();
    await expect(owner.locator('#share-url')).toBeVisible();
    const sharedLink = await owner.locator('#share-url').inputValue();
    const slug = `gate-${Date.now().toString(36)}`;
    await owner.locator('#share-slug').fill(slug);
    await owner.getByRole('button', { name: 'Save the address' }).click();
    await expect(owner.getByText(`/d/${slug}`).first()).toBeVisible();

    const visitor = await (await browser.newContext()).newPage();
    await visitor.goto(sharedLink);
    await expect(visitor.locator('h1.topbar-title')).toBeVisible();
    await expectNoSeriousA11y(visitor, 'shared page');
    await visitor.goto(`/d/${slug}`);
    await expect(visitor.locator('h1.topbar-title')).toBeVisible();
    await expectNoSeriousA11y(visitor, 'published page');

    await owner.locator('#share-password').fill('gate-e2e-secret');
    await owner.getByRole('button', { name: 'Save the link settings' }).click();
    await expect(owner.getByText('A password is set.')).toBeVisible();

    const locked = await (await browser.newContext()).newPage();
    for (const link of [sharedLink, `${baseURL}/d/${slug}`]) {
        await locked.goto(link);
        await expect(locked.getByRole('heading', { name: 'This one is locked' })).toBeVisible();
        await expectNoSeriousA11y(locked, `password page (${link.includes('/d/') ? 'published' : 'shared'})`);
        await locked.locator('#password').fill('wrong');
        await locked.getByRole('button', { name: 'Open the document' }).click();
        await expect(locked.locator('#password')).toHaveAttribute('aria-invalid', 'true');
        await expect(locked.locator('#password-error')).toHaveText('Incorrect password.');
        await expectNoSeriousA11y(locked, 'password page with a refused password');
    }
});

test('the open menus have no violations: account, platforms, notifications, header search and the editor\'s More menu', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await page.goto('/documents');

    const open = async (trigger: string, panel: string, label: string) => {
        const button = page.locator(trigger).first();
        await button.click();
        await expect(button).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator(panel).first()).toBeVisible();
        await expectNoSeriousA11y(page, label);
        await page.keyboard.press('Escape');
    };
    await open('.dh-account__btn', '.dh-account .dh-menu', 'account menu');
    await open('[data-dsw-trigger]:visible', '.dsw-panel, [data-dsw-panel]', 'platform switcher');
    if (await page.locator('.dh-bell--panel').count()) {
        await open('.dh-bell--panel', '.dh-bellpanel', 'notifications panel');
    }

    const search = page.locator('.shell-search input[type="search"]');
    await search.fill('Welcome');
    await expect(page.locator('.shell-search .dsr-item').first()).toBeVisible();
    await expectNoSeriousA11y(page, 'header search with results');
    await page.keyboard.press('Escape');

    await openWelcomePack(page);
    // (The editor's Tools button only exists below the doc bar's narrow breakpoint.)
    for (const name of ['More']) {
        const button = page.getByRole('button', { name, exact: true }).first();
        await expect(button, `the editor's ${name} button`).toBeVisible();
        await button.click();
        await expect(button).toHaveAttribute('aria-expanded', 'true');
        await expectNoSeriousA11y(page, `editor ${name} menu`);
        await page.keyboard.press('Escape');
        if ((await button.getAttribute('aria-expanded')) === 'true') await button.click();
    }
});

test('the welcome page is readable with scripts blocked', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/');
    const hero = page.locator('[data-reveal]').first();
    await expect(hero).toBeVisible();
    expect(await hero.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');
    const hidden = await page.locator('[data-reveal]').evaluateAll((els) => els.filter((el) => getComputedStyle(el).opacity !== '1').length);
    expect(hidden, 'every section is visible without scripts').toBe(0);
    await context.close();
});

/** Contrast of one side of an element's border against the first opaque background behind it. */
async function edgeContrast(page: Page, selector: string, side: 'Top' | 'Bottom'): Promise<number> {
    return page.locator(selector).first().evaluate((el, edge) => {
        const rgb = (c: string) => (c.match(/[\d.]+/g) ?? []).map(Number);
        const lum = ([r, g, b]: number[]) => {
            const ch = (v: number) => { const x = v / 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; };
            return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
        };
        let ground = 'rgb(255,255,255)';
        for (let e: Element | null = el.parentElement; e; e = e.parentElement) {
            const parts = rgb(getComputedStyle(e).backgroundColor);
            if (parts.length === 3 || (parts.length === 4 && parts[3] > 0)) { ground = getComputedStyle(e).backgroundColor; break; }
        }
        const a = lum(rgb((getComputedStyle(el) as unknown as Record<string, string>)[`border${edge}Color`]));
        const b = lum(rgb(ground));
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }, side);
}

test('the panel inputs show an edge of 3:1: the command palette field, the title and the style picker when shown', async ({ browser }) => {
    const page = await as(browser, users.owner);
    await openWelcomePack(page);
    await page.locator('.doc-title-field').hover();
    expect(await edgeContrast(page, '.doc-title-field', 'Top')).toBeGreaterThanOrEqual(3);
    await page.locator('.tool-select').first().hover();
    expect(await edgeContrast(page, '.tool-select', 'Top')).toBeGreaterThanOrEqual(3);
    await page.locator('.ProseMirror').click();
    await page.keyboard.press('ControlOrMeta+K');
    await expect(page.locator('.dotdoc-panel-input')).toBeFocused();
    expect(await edgeContrast(page, '.dotdoc-panel-input', 'Bottom')).toBeGreaterThanOrEqual(3);
});
