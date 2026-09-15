/**
 * Rendered-pair contrast gate for the Dot.Doc shell.
 *
 * WHY THIS EXISTS. The first pass measured TOKEN pairs — every text token
 * against the two grounds — and reported ALL PASS while the dock's flagship
 * button rendered its "⇧⌘K" at 1.00:1 and the command palette's active row
 * rendered its hint at 2.2:1. Neither pair is a token pair: both are a rule
 * (.micro {color: var(--ink-soft)}) landing inside another rule's fill
 * (.btn-primary {background: var(--accent)}). A token table cannot see that,
 * by construction.
 *
 * WHAT IT MEASURES. Pairs of RULES, the way the browser resolves them:
 *
 *   1. Read :root (day) and html.dark (night) into two token maps.
 *   2. Parse every rule in shell.css and paper.css, expanding :is(a, b) c into
 *      the selectors it stands for, so a grouped rule is matchable by name.
 *   3. For each declared pair {ink inside surface}, resolve the ink's WINNING
 *      colour: a descendant rule "<surface> <ink>" beats the bare "<ink>" rule
 *      (higher specificity, and it is written later), and `inherit` resolves to
 *      the surface's own `color` — falling back to body's.
 *   4. Resolve the surface's background the same way, compositing any
 *      color-mix(... N%, transparent) over what is behind it.
 *   5. Report WCAG 2.1 contrast against the floor for that kind of pair.
 *
 * Text floor 4.5:1. Status dots and other non-text marks take 3:1 (WCAG
 * 1.4.11). Fair Copy has NO exempt rows: the ghosted padding zero that was the
 * single documented exemption went with the mono readout it decorated, so every
 * row in this table now carries a real floor.
 *
 *   node scripts/design/contrast-dom.mjs           # table + exit 1 on a FAIL
 *   node scripts/design/contrast-dom.mjs --canary  # prove the harness is live
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const SHEETS = ['resources/css/shell.css', 'resources/css/paper.css'];

/* ── CSS reading ──────────────────────────────────────────────────────── */

/** Strip comments and at-rule wrappers so the rule list is flat. */
function flatten(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/**
 * Split a selector list on TOP-LEVEL commas only. Splitting naively breaks
 * `:is(a, b) c` into `:is(a` and `b) c`, which silently matches nothing — and
 * a contrast gate that silently matches nothing is worse than no gate at all.
 */
function splitTopLevel(head) {
    const out = [];
    let depth = 0;
    let start = 0;

    for (let i = 0; i < head.length; i += 1) {
        const ch = head[i];
        if (ch === '(' || ch === '[') depth += 1;
        else if (ch === ')' || ch === ']') depth -= 1;
        else if (ch === ',' && depth === 0) {
            out.push(head.slice(start, i));
            start = i + 1;
        }
    }
    out.push(head.slice(start));

    return out.map((s) => s.trim()).filter(Boolean);
}

/** Expand `:is(a, b) :is(c, d)` into every selector it stands for. */
function expandIs(selector) {
    const match = selector.match(/:is\(([^()]*)\)/);
    if (!match) return [selector.replace(/\s+/g, ' ').trim()];

    return splitTopLevel(match[1])
        .flatMap((option) =>
            expandIs(selector.slice(0, match.index) + option.trim() + selector.slice(match.index + match[0].length))
        );
}

/**
 * Every rule in source order, as {selector, decls}. Nested at-rules (@media,
 * @container, @supports) are unwrapped: their contents are ordinary rules for
 * the purposes of "which declaration wins", and we only ever ask about rules
 * that are unconditional in practice.
 */
function readRules(css) {
    const rules = [];
    const body = flatten(css);
    const re = /([^{}]+)\{([^{}]*)\}/g;
    let m;

    while ((m = re.exec(body)) !== null) {
        const head = m[1].trim();
        if (head.startsWith('@')) continue;

        const decls = {};
        for (const part of m[2].split(';')) {
            const i = part.indexOf(':');
            if (i === -1) continue;
            decls[part.slice(0, i).trim()] = part.slice(i + 1).trim();
        }

        for (const group of splitTopLevel(head)) {
            for (const selector of expandIs(group)) {
                if (selector) rules.push({ selector, decls });
            }
        }
    }

    return rules;
}

/** The token maps: :root is day, html.dark is night. */
function readTokens(rules) {
    const day = {};
    const night = {};

    for (const rule of rules) {
        const target = rule.selector === ':root' ? day : rule.selector === 'html.dark' ? night : null;
        if (!target) continue;
        for (const [prop, value] of Object.entries(rule.decls)) {
            if (prop.startsWith('--')) target[prop] = value;
        }
    }

    return { day, night: { ...day, ...night } };
}

/* ── Colour ───────────────────────────────────────────────────────────── */

function parseColor(value) {
    const v = value.trim();

    let m = v.match(/^#([0-9a-f]{6})([0-9a-f]{2})?$/i);
    if (m) {
        const n = parseInt(m[1], 16);
        return { r: n >> 16, g: (n >> 8) & 255, b: n & 255, a: m[2] ? parseInt(m[2], 16) / 255 : 1 };
    }

    m = v.match(/^#([0-9a-f]{3})$/i);
    if (m) {
        const [r, g, b] = m[1].split('').map((c) => parseInt(c + c, 16));
        return { r, g, b, a: 1 };
    }

    m = v.match(/^rgba?\(([^)]+)\)$/i);
    if (m) {
        const parts = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
        return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
    }

    if (v === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
    if (v === 'white') return { r: 255, g: 255, b: 255, a: 1 };
    if (v === 'black') return { r: 0, g: 0, b: 0, a: 1 };

    return null;
}

/** Composite a possibly-translucent colour over an opaque one. */
function over(fg, bg) {
    if (fg.a >= 1) return fg;

    return {
        r: fg.r * fg.a + bg.r * (1 - fg.a),
        g: fg.g * fg.a + bg.g * (1 - fg.a),
        b: fg.b * fg.a + bg.b * (1 - fg.a),
        a: 1,
    };
}

function luminance({ r, g, b }) {
    const [R, G, B] = [r, g, b].map((c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * R + 0.7152 * G + 0.0722 * B;
}

function contrast(a, b) {
    const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);

    return (x + 0.05) / (y + 0.05);
}

function hex({ r, g, b }) {
    return '#' + [r, g, b].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('');
}

/**
 * Resolve a CSS value to a colour, following var() and color-mix(). `behind`
 * is what a translucent result is composited over.
 */
function resolve1(value, tokens, behind, depth = 0, current = null) {
    if (!value || depth > 8) return null;
    const v = value.trim();

    // currentColor is what the inverted-surface rules are written in: a lamp
    // painted from the ink it is sitting in, a ghost mixed out of it.
    if (v === 'currentColor' || v === 'inherit') return current;

    const varMatch = v.match(/^var\((--[\w-]+)(?:\s*,\s*([\s\S]+))?\)$/);
    if (varMatch) {
        return resolve1(tokens[varMatch[1]] ?? varMatch[2], tokens, behind, depth + 1, current);
    }

    // color-mix(in srgb, <colour> N%, transparent) — the only form the shell
    // uses, and the one the ghosted padding zero is drawn with.
    const mix = v.match(/^color-mix\(\s*in\s+srgb\s*,\s*([\s\S]+?)\s+([\d.]+)%\s*,\s*([\s\S]+?)\s*\)$/);
    if (mix) {
        const a = resolve1(mix[1], tokens, behind, depth + 1, current);
        const pct = Number(mix[2]) / 100;
        const b = mix[3].trim() === 'transparent' ? { r: 0, g: 0, b: 0, a: 0 } : resolve1(mix[3], tokens, behind, depth + 1, current);
        if (!a || !b) return null;

        return over(
            {
                r: a.r * pct + b.r * (1 - pct),
                g: a.g * pct + b.g * (1 - pct),
                b: a.b * pct + b.b * (1 - pct),
                a: a.a * pct + b.a * (1 - pct),
            },
            behind
        );
    }

    const parsed = parseColor(v);

    return parsed ? over(parsed, behind) : null;
}

/* ── The cascade, for the one question we ask ─────────────────────────── */

/**
 * The winning declaration of `prop` for `ink` rendered inside `surface`.
 *
 * A descendant rule ("<surface> <ink>") beats a bare "<ink>" rule: it is more
 * specific AND written later, so on both counts it is what the browser paints.
 * This is exactly the mechanism the token table could not see.
 */
function winner(rules, surface, ink, prop) {
    let bare = null;
    let nested = null;

    for (const rule of rules) {
        if (!(prop in rule.decls)) continue;
        if (rule.selector === ink) bare = rule.decls[prop];
        if (surface && rule.selector === `${surface} ${ink}`) nested = rule.decls[prop];
    }

    return { value: nested ?? bare, nested: nested !== null, inherited: (nested ?? bare) === 'inherit' };
}

function surfaceBackground(rules, surface, tokens, behind) {
    for (let i = rules.length - 1; i >= 0; i -= 1) {
        if (rules[i].selector !== surface) continue;
        const raw = rules[i].decls['background'] ?? rules[i].decls['background-color'];
        if (raw) return resolve1(raw.split(/\s+/)[0], tokens, behind) ?? behind;
    }

    return behind;
}

function surfaceColor(rules, surface, tokens, behind) {
    for (let i = rules.length - 1; i >= 0; i -= 1) {
        if (rules[i].selector !== surface) continue;
        if (rules[i].decls['color']) return resolve1(rules[i].decls['color'], tokens, behind);
    }

    return null;
}

/* ── The pairs, taken from what the views actually render ─────────────── */

const GROUND = [
    { surface: 'body', note: 'the ground' },
    { surface: '.panel', note: 'a panel' },
    { surface: '.rail', note: 'the rail' },
    { surface: '.dock', note: 'the dock' },
    { surface: '.topbar', note: 'the top bar' },
    { surface: '.sheet', note: 'a sheet' },
    { surface: '.menu-list', note: 'a menu' },
    { surface: '.note', note: 'a note' },
    { surface: '.note-danger', note: 'a danger note' },
    { surface: '.dotdoc-panel', note: 'the command palette' },
    { surface: '.dotdoc-bubble', note: 'the floating contextual toolbar' },
];

const INKS = [
    '.micro',
    '.micro-lg',
    '.status-word',
    '.list-sub',
    '.list-val',
    '.field-hint',
    '.field-error',
    '.field-label',
    '.rail-item',
    '.rail-item-note',
    '.rail-label',
    '.rail-colophon',
    '.rail-account-team',
    '.dock-tab',
    '.topbar-action',
    '.topbar-toggle',
    '.topbar-title',
    '.empty-line',
    '.section-title',
    '.page-lede',
    '.link',
    '.dotdoc-panel-hint',
    '.dotdoc-panel-title',
    '.dotdoc-bubble-btn',
];

/** The inverted surfaces — the pairs the token table was blind to. */
const INVERTED = [
    { surface: '.btn-primary', inks: ['.micro', '.status-word', '.list-sub', '.field-label', '.numeral'], note: 'primary button' },
    { surface: ".tag[aria-pressed='true']", inks: ['.micro', '.status-word', '.numeral'], note: 'pressed tag' },
    { surface: '.tool.is-on', inks: ['.micro', '.status-word', '.numeral'], note: 'active tool' },
    { surface: '.dotdoc-panel-row.is-active', inks: ['.dotdoc-panel-hint', '.dotdoc-panel-label'], note: 'active palette row' },
    { surface: '.dotdoc-bubble-btn.is-active', inks: ['.dotdoc-panel-hint', '.micro'], note: 'active bubble button' },
];

/** The non-text marks: the three status dots and the field error's own dot. */
const MARKS = [
    { token: '--accent', note: 'a good dot' },
    { token: '--danger', note: 'a danger dot' },
    { token: '--ink-soft', note: 'an idle dot' },
];

const GROUND_TOKENS = ['--ground', '--surface', '--surface-raised'];

function run() {
    const canary = process.argv.includes('--canary');
    let css = SHEETS.map((f) => readFileSync(resolve(ROOT, f), 'utf8')).join('\n');
    if (canary) {
        // Break exactly the rule that was broken in review, and prove the
        // harness reports it. Without this the table is unfalsifiable.
        css = css.replace(
            /:is\(\.inverted, \.btn-primary, \.tool\.is-on, \.tag\[aria-pressed='true'\]\)\n {4}:is\(\.micro[^}]*\}/,
            ''
        );
    }

    const rules = readRules(css);
    const tokens = readTokens(rules);
    const rows = [];

    for (const mode of ['night', 'day']) {
        const map = tokens[mode];
        const ground = resolve1('var(--ground)', map, { r: 255, g: 255, b: 255, a: 1 });

        // 1. Plain text on every ground the chrome actually paints.
        for (const { surface, note } of GROUND) {
            const bg = surfaceBackground(rules, surface, map, ground);
            for (const ink of INKS) {
                const win = winner(rules, null, ink, 'color');
                if (!win.value) continue;
                const fg = resolve1(win.value, map, bg);
                if (!fg) continue;
                rows.push({ mode, kind: 'text', pair: `${ink} on ${note}`, fg, bg, floor: 4.5 });
            }
        }

        // 2. The inverted surfaces: the ink's WINNING colour, not its own.
        for (const { surface, inks, note } of INVERTED) {
            const bg = surfaceBackground(rules, surface, map, ground);
            const inheritTo = surfaceColor(rules, surface, map, bg) ?? resolve1('var(--ink)', map, bg);

            for (const ink of inks) {
                const win = winner(rules, surface, ink, 'color');
                const fg = win.inherited ? inheritTo : resolve1(win.value ?? 'inherit', map, bg, 0, inheritTo) ?? inheritTo;
                rows.push({ mode, kind: 'text', pair: `${ink} inside ${note}`, fg, bg, floor: 4.5 });
            }
        }

        // 3. Marks: non-text indicators, 3:1, on every ground they land on.
        for (const { token, note } of MARKS) {
            for (const groundToken of GROUND_TOKENS) {
                const bg = resolve1(`var(${groundToken})`, map, ground);
                rows.push({
                    mode,
                    kind: 'mark',
                    pair: `${note} on ${groundToken}`,
                    fg: resolve1(`var(${token})`, map, bg),
                    bg,
                    floor: 3,
                });
            }
        }

        // 4. Ink on paper. The canvas does NOT invert - CssBuilder writes
        //    `.paper{background:#fff}` and Document Styles are out of scope for
        //    this phase - so --paper / --paper-ink are their own non-inverting
        //    pair, measured in both modes rather than assumed.
        for (const ink of ['--paper-ink', '--marker']) {
            const bg = resolve1('var(--paper)', map, ground);
            rows.push({ mode, kind: 'text', pair: `${ink} on --paper`, fg: resolve1(`var(${ink})`, map, bg), bg, floor: 4.5 });
        }

        // 5. The same machine ink shown in the CHROME, where the ground does
        //    invert. This is the pair that caught --marker being flipped for
        //    night while the paper under it stayed white.
        for (const groundToken of ['--surface', '--surface-raised']) {
            const bg = resolve1(`var(${groundToken})`, map, ground);
            rows.push({
                mode,
                kind: 'text',
                pair: `.ink-marker on ${groundToken}`,
                fg: resolve1('var(--marker-chrome)', map, bg),
                bg,
                floor: 4.5,
            });
        }
    }

    const width = Math.max(...rows.map((r) => r.pair.length));
    const line = [];
    let failed = 0;

    line.push(`| mode  | kind       | ${'pair'.padEnd(width)} | fg      | bg      | ratio | floor | verdict |`);
    line.push(`|-------|------------|-${'-'.repeat(width)}-|---------|---------|-------|-------|---------|`);

    for (const row of rows) {
        if (!row.fg || !row.bg) continue;
        const ratio = contrast(row.fg, row.bg);
        const verdict = ratio >= row.floor ? 'PASS' : 'FAIL';
        if (verdict === 'FAIL') failed += 1;
        line.push(
            `| ${row.mode.padEnd(5)} | ${row.kind.padEnd(10)} | ${row.pair.padEnd(width)} | ${hex(row.fg)} | ${hex(row.bg)} | ${ratio.toFixed(2).padStart(5)} | ${String(row.floor).padStart(5)} | ${verdict.padEnd(7)} |`
        );
    }

    console.log(line.join('\n'));
    console.log(failed === 0 ? '\nALL PASS' : `\n${failed} FAILED`);

    if (canary) {
        console.log(failed > 0 ? 'CANARY OK — the harness sees the inverted-surface rule.' : 'CANARY DEAD — the harness is vacuous.');
        process.exit(failed > 0 ? 0 : 1);
    }

    process.exit(failed === 0 ? 0 : 1);
}

run();
