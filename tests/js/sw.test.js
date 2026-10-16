import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * Run public/sw.js in a sandbox that stands in for a service worker.
 *
 * @param {{fetchImpl?: Function}} options `fetchImpl` replaces the network;
 *        by default every request is answered 200.
 * @returns {{listeners: object, calls: {databaseOpens: number}}} the event
 *          listeners the worker registered, by event name, and a count of
 *          how often it opened IndexedDB.
 */
function loadServiceWorker({ fetchImpl } = {}) {
    const listeners = {};
    const calls = { databaseOpens: 0 };
    const sandbox = {
        URL,
        Response,
        Promise,
        console,
        indexedDB: {
            open: () => {
                calls.databaseOpens += 1;
                throw new Error('no IndexedDB in this sandbox');
            },
        },
        caches: {
            match: async () => undefined,
            open: async () => ({ put: async () => undefined, addAll: async () => undefined }),
            keys: async () => [],
            delete: async () => true,
        },
        fetch: fetchImpl ?? (async () => new Response('ok', { status: 200 })),
    };
    sandbox.self = {
        location: { origin: 'https://doc.test' },
        addEventListener: (type, fn) => {
            listeners[type] = fn;
        },
        skipWaiting: () => {},
        clients: { claim: async () => {} },
        registration: {},
    };

    vm.runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), sandbox);

    return { listeners, calls };
}

function fetchEvent(method, path, mode = 'cors') {
    const event = {
        responded: false,
        response: null,
        request: {
            method,
            mode,
            url: `https://doc.test${path}`,
            clone() {
                return this;
            },
            text: async () => '{}',
        },
        respondWith(promise) {
            event.responded = true;
            event.response = Promise.resolve(promise);
            event.response.catch(() => {});
        },
    };

    return event;
}

test('the sync poll is not intercepted', () => {
    const event = fetchEvent('POST', '/documents/abc/sync');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, false);
});

test('the unload beacon is not intercepted', () => {
    const event = fetchEvent('POST', '/documents/abc/autosave');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, false);
});

test('an offline Livewire POST fails as a network error, and nothing is queued for later', async () => {
    const worker = loadServiceWorker({
        fetchImpl: async () => {
            throw new TypeError('Failed to fetch');
        },
    });
    const event = fetchEvent('POST', '/livewire/update');
    worker.listeners.fetch(event);

    assert.equal(event.responded, true);

    // A network error, which makes the page's own fetch() reject - not a
    // made-up 200 that Livewire then chokes on.
    const response = await event.response;
    assert.equal(response.type, 'error');

    // Nothing was stored for a later replay, and nothing listens for one.
    assert.equal(worker.calls.databaseOpens, 0);
    assert.equal(worker.listeners.sync, undefined);
});

test('a static asset is still served through the cache', () => {
    const event = fetchEvent('GET', '/build/assets/app-abc.js');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, true);
});

test('a page navigation still gets the network-first handler with its offline fallback', () => {
    const event = fetchEvent('GET', '/documents', 'navigate');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, true);
});
