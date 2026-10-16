/**
 * Dot.Docs Service Worker
 * - Caches static assets for offline shell
 * - Passes Livewire requests straight to the network. It does NOT keep a
 *   failed save for later: see handleLivewirePost().
 */

const CACHE_NAME = 'dotdocs-v1';

// Assets to pre-cache (shell). Vite build output filenames change; we cache
// dynamically on first fetch instead (network-first with offline fallback).
const PRECACHE_URLS = ['/'];

// ── Install: pre-cache shell ─────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
    );
    self.skipWaiting();
});

// ── Activate: claim clients immediately ──────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ── Fetch interception ────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle same-origin requests
    if (url.origin !== self.location.origin) return;

    // Livewire requests — to the network; offline they fail (see below)
    if (request.method === 'POST' && url.pathname.includes('/livewire/')) {
        event.respondWith(handleLivewirePost(request));
        return;
    }

    // Every other request that is not a GET goes to the network untouched.
    // The branches below are for pages and static files; the editor's sync
    // poll and its unload beacon are POSTs, the Cache API cannot store a
    // POST, and running them through "cache first" only produced a rejected
    // cache.put() on every call. A form post is not answered from the cache
    // either.
    if (request.method !== 'GET') return;

    // Navigation requests — network first, fall back to cache
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(request).then((cached) => cached || caches.match('/'))
            )
        );
        return;
    }

    // Static assets — cache first, network fallback + cache update
    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) return cached;
            return fetch(request).then((networkRes) => {
                if (networkRes && networkRes.status === 200 && networkRes.type === 'basic') {
                    const clone = networkRes.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                }
                return networkRes;
            });
        })
    );
});

// ── Livewire requests ─────────────────────────────────────────────────────────
// Straight to the network. When the network is down the page gets what a
// browser with no service worker would give it: a failed request.
//
// This used to store the request in IndexedDB, answer the page with a
// made-up `{effects: [], components: []}` body, and send the stored request
// again when the connection came back. Neither half worked. Livewire cannot
// read that body: it throws, never releases the request, and every later
// action on the page - saving included - stays pending until reload. And a
// replayed save is a whole-document copy from before the connection dropped,
// sent behind the page's back. Text typed offline is protected by the
// offline draft (resources/js/offline.js); the editor page sends it itself
// once it is back online.
async function handleLivewirePost(request) {
    try {
        return await fetch(request.clone());
    } catch (_) {
        return Response.error();
    }
}

// ── IndexedDB ─────────────────────────────────────────────────────────────────
// This worker no longer uses IndexedDB. The `dotdocs-offline` database and its
// layout belong to resources/js/offline.js. Its `saves` store held the save
// queue that has been removed from this file; offline.js still creates it,
// only so that no browser needs a schema upgrade.
