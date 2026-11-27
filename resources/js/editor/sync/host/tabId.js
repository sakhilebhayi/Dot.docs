/**
 * This tab's identity for presence and the sync poll. Per page load, not
 * per browser: two tabs of one account are two tabs.
 *
 * `crypto.randomUUID` exists only in a secure context, so over plain HTTP
 * and in older browsers the id is built from 16 bytes of
 * `crypto.getRandomValues` instead, which is just as hard to guess. Only a
 * browser with no crypto object at all falls back to `Math.random`.
 * Whichever it is, the result matches the server's rule for a tab id: 1 to
 * 64 characters of `[A-Za-z0-9-]`.
 *
 * @param {{
 *   crypto: {randomUUID?: () => string, getRandomValues?: (bytes: Uint8Array) => Uint8Array}|null|undefined,
 *   random: () => number,
 *   now: () => number,
 * }} sources the page passes `window.crypto`, `Math.random` and `Date.now`
 * @returns {string}
 */
export function createTabId({ crypto, random, now }) {
    if (crypto && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    if (crypto && typeof crypto.getRandomValues === 'function') {
        const bytes = crypto.getRandomValues(new Uint8Array(16));

        return `tab-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
    }

    return `tab-${random().toString(36).slice(2)}${now().toString(36)}`;
}
