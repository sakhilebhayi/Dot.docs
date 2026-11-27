import { commands, run as runCommand } from '../commands/registry';
import { base62 } from '../extensions/blockId';
import { documentsDiffer, stripDerived } from '../derived';
import { outline, setOutline } from '../outline';
import { openPalette } from '../ui/palette';
import { createSyncEngine } from '../sync/engine';
import { createSyncHost, createTabId } from '../sync/host';
import { createMetrics } from '../sync/metrics';
import { createSyncRequest } from '../sync/request';
import { mount } from './mount';

/** The editor mounted on `element`, if any. Always the raw, unproxied handle. */
function get(element) {
    return element?.__dotdoc ?? null;
}

export const DotDoc = {
    mount,
    get,
    setOutline,
    outline,
    commands,
    run: runCommand,
    openPalette,
    base62,
    // The Blade bridge compares an offline draft against the server document;
    // Outline::apply() stamps toc.entries and crossRef.label into the stored
    // JSON, so those have to come off both sides before the comparison means
    // anything.
    stripDerived,
    documentsDiffer,
    // The follow-other-people's-saves loop. The Blade bridge builds one
    // engine per editor page (sync/engine.js) and hands every decision about
    // a save, a newer document or a draft to createSyncHost() (sync/host.js),
    // which it builds from its own state for each call.
    sync: { createSyncEngine, createSyncRequest, createSyncHost, createTabId, createMetrics },
    /** The single active editor's pagination controller, or a safe no-op stand-in before mount(). */
    get pagination() {
        const el = document.querySelector('[wire\\:ignore].canvas, #doc-paper');
        return el?.__dotdoc?.pagination || {
            mode: 'continuous', setMode() {}, pageCount: 1, currentPage: 1, goToPage() {}, setPageSetup() {},
        };
    },
};
