{{-- The editor's Alpine component: the whole value of the root element's x-data attribute in
     livewire/documents/editor.blade.php, included there between the attribute's quotes. It is ONE html
     attribute, so no double quote may appear anywhere in it, comments included: the first one would
     close the attribute (ContextualToolbarTest pins this on the rendered page). Included, not a
     component, so it shares the Livewire component's scope ($this, @this, $document). --}}
{
    owns: false,
    echo: null,
    // This tab's identity for presence and the sync poll. Per page load,
    // not per browser: two tabs of one account are two tabs. Made in
    // init(), not here: the editor bundle makes it (createTabId() in
    // sync/host.js), and Alpine can read this object before that
    // bundle has loaded.
    tabId: '',
    // How many of this tab's own saves are in the air, and when the
    // latest one left. The sync engine decides nothing about a newer
    // document until they have settled - but a request that never
    // answers must not freeze following for ever, so `busy` expires.
    saving: 0,
    savingSince: 0,
    // Every save this tab sends is numbered, and the number of the last
    // save given up on is kept: an answer that arrives after its save
    // was given up on is used only while the page has learnt nothing
    // newer since (see persist() in sync/host.js).
    saveSeq: 0,
    expiredSaveSeq: 0,
    // True from the first local edit until a save of exactly what the
    // editor holds has been confirmed by the server. The bundle's own
    // `pending` flag is not enough: it drops the moment a document is
    // HANDED to persist(), long before the server has stored it.
    unsaved: false,
    // A save is owed: one was asked for while another was in the air,
    // or one never answered. resendIfOwed() sends it.
    resave: false,
    // The document, as a JSON string, as the server last confirmed it:
    // what the page opened with, what the last accepted save stored, or
    // the last server document applied. It is the document of the
    // version baseVersion names; null again whenever that is not known
    // (one of this tab's saves went unanswered, or Keep mine moved the
    // base up), until a save is accepted or a server document applied.
    // See settleIfBackAtConfirmed() in sync/host.js.
    confirmed: null,
    // This tab's text is to replace, on purpose, the version its base
    // now names: Keep mine moved the base up to it, or Put it back
    // replaces the version that was loaded. Every save says so (the
    // unload beacon too) until one that said so is accepted.
    overwriteOwed: false,
    // The base the owed overwrite first became owed on, null while none
    // is owed: the offline draft is stamped below it, so a draft typed
    // after Keep mine is never merged as if nothing were owed (see
    // draftStamp() in sync/host.js).
    overwriteFloor: null,
    // Set while a newer version exists on the server AND this tab holds
    // unsaved typing. Saving is suspended until the writer chooses.
    // `version` is the newest version known to be in the way; `ready`
    // is whether that document has been downloaded yet.
    conflict: null,
    // The writer's own text, as a JSON string, after they chose to load
    // the newer version - or a draft from an earlier visit that the
    // document has since moved past. Kept so the page can offer to put
    // it back.
    setAside: null,
    // Where setAside came from, and the document version the page
    // showed when the text was set aside. The source is 'conflict'
    // (Load theirs, a moment ago in this tab) or 'draft' (a draft found
    // at page load that the document has moved past: its text may
    // already be part of the document). Put it back asks first when the
    // source is a draft, when baseVersion is no longer setAsideBase, or
    // when the page holds text that is not saved yet. Both are null
    // while nothing is set aside, and everything that clears setAside
    // clears them with it.
    setAsideFrom: null,
    setAsideBase: null,
    // Phase 2, the paragraph-level merge (sync/host.js writes all seven).
    // merging: {version, since} while a newer document is being fetched
    // to merge the typing into; persist() sends nothing meanwhile.
    merging: null,
    // Merges after refused saves, in a row: MERGE_ATTEMPTS (5, sync/host.js) end in the notice.
    mergeAttempts: 0,
    // The last server document this tab held, {version, json}, as the
    // editor serialises it. Never nulled: confirmed is nulled on purpose
    // (a lost save answer, Keep mine) while this is still the merge base.
    known: null,
    // The JSON string of the save in the air, and of the last save given
    // up on or whose request failed: the echo a merge recognises as this
    // tab's own change (section 5.6 of the Phase 2 spec).
    sentSnapshot: null,
    unanswered: null,
    // What a merge could not keep, oldest first: the lost row offers it
    // back (section 6 of the Phase 2 spec).
    lost: [],
    // The saveSeq at the moment Put it back last replaced the text: a save sent at or before it did not carry that text.
    putBackSeq: 0,
    // An offer of a kept park was held back (a conflict, a merge or a stop reason stood): the poll tick makes it when they clear.
    parkOfferPending: false,
    // Consecutive saves whose request failed, and the time before which the next one is held back (sync/host.js, SAVE_RETRY_DELAYS_MS).
    saveFailures: 0,
    // What the notice bar's last pressed action returned (see pressed() and noticePressed() below).
    lastPress: undefined,
    saveRetryAt: 0,
    // A reason the page can no longer stay in step (signed out, access
    // removed, document deleted, a version this editor cannot open).
    // Shown in the notice bar.
    syncNotice: '',
    // The people last reported by the poll, as a comparable string, so
    // the presence strip is only re-rendered when it actually changes.
    memberKey: '',
    isTyping: false,
    typingTimeout: null,
    isOffline: !navigator.onLine,
    docUuid: '{{ $document->uuid }}',
    // Whether the server drew this page for somebody who may change the
    // document (DocumentPolicy::update()). The only server-drawn literal
    // that decides a gate (besides the versions, the uuid and metricsOn), and a plain boolean: it is the
    // page-load truth sync/host.js decides read only by (a person who may
    // not change the document never saves, drafts or sends a beacon, and
    // the editor is mounted not editable). It turns false on this object
    // when a save is refused with 403 (access lowered while the page was
    // open): the host sets it, the notice bar reads it.
    editable: {{ $canEdit ? 'true' : 'false' }},
    // Text typed on this page was left unsent because that refusal came
    // before it was stored; a copy is kept in this browser.
    unsentKept: false,
    // When the question may I still change this was put to the server
    // after a save came back 403, and 0 while none is out. The 403
    // alone is not proof (a firewall, or another action sent with the
    // save, can cause it), so saves wait for the answer
    // (sync/host.js, saveRefused()).
    accessCheckSince: 0,
    // Takes the refusal hook off Livewire when the page is really removed.
    unhookRefusal: null,
    // Whether the server collects the co-editing counters (config docs.sync_metrics, env DOCS_SYNC_METRICS).
    // A plain boolean, the same on every render of one deploy, so it never changes this string between
    // renders. The counters themselves live in sync/metrics.js and on the editor element.
    metricsOn: {{ config('docs.sync_metrics') ? 'true' : 'false' }},
    // The document version this page was rendered from, and the version
    // the last successful save reported. Draft recency is decided by
    // VERSION, never by a clock: `savedAt` comes off the browser and
    // `updated_at` off the server, so a client whose clock runs slow would
    // throw away real offline work. A draft is restorable only while its
    // baseVersion still equals the document's version - i.e. nobody has
    // saved since it was written.
    documentVersion: {{ $document->version }},
    baseVersion: {{ $document->version }},
    selection: { blockId: null, type: null },
    aiError: '',
    tick: 0,
    thumbnailsOpen: false,
    // The phone's document-tools sheet (see .doc-bar-tools); a desktop never opens it.
    toolsOpen: false,
    viewMode: 'continuous',

    // The editor is NEVER stored in Alpine's reactive data: a reactivity
    // proxy around it hands every command a proxied EditorState and
    // ProseMirror then rejects the transaction it builds ('Applying a
    // mismatched transaction'). Read it through this accessor instead,
    // which returns the raw handle the bundle parked on the element.
    // Reading `tick` keeps toolbar :class bindings re-evaluating as the
    // selection moves.
    ed() {
        this.tick;
        return window.DotDoc?.get(this.$refs.editorEl)?.editor ?? null;
    },

    // The top bar's status word reports what THIS page knows, and only
    // because this page registered itself as the owner of that word (see
    // components/shell/topbar.blade.php). shell.js used to hook every
    // Livewire commit instead, which reported 'Saved' for a search box.
    report(tone, word) {
        window.dispatchEvent(new CustomEvent('shell:save-state', { detail: { tone, word } }));
    },

    // A button in the notice bar was pressed from the keyboard: hand the
    // keyboard back to the document. The row that held the button goes
    // away once the choice has been carried out (at once, or when the
    // save answers), and the focus would be left on nothing. The notice
    // bar calls this for every click inside it, after the button's own
    // handler. Only a press from the keyboard counts (a click the
    // browser made from Enter or Space has detail 0): after a tap,
    // focusing the paper would bring up the on-screen keyboard over a
    // document the writer has just asked to look at. No scrolling:
    // nobody asked to go anywhere. This is page glue about focus, not a
    // decision about a save; sync/host.js has no DOM.
    // An action that was declined or failed (it returned false) leaves the focus where it is: the
    // row stays, and moving the keyboard to the paper would take it away from the button.
    pressed(result) { this.lastPress = result; return result; },
    noticePressed(event) {
        const done = this.lastPress;
        this.lastPress = undefined;
        if (event.detail !== 0 || !event.target.closest('button')) return;
        Promise.resolve(done).then((completed) => {
            if (this.syncHost().refocusAfterPress(completed)) this.ed()?.commands.focus(null, { scrollIntoView: false });
        });
    },

    init() {
        // Alpine now comes only from Livewire's bundle (the duplicate CDN
        // tag is gone from layouts/app.blade.php — two Alpines break
        // Livewire outright), but x-init can still run before the editor
        // bundle has loaded, and Livewire re-inits this element after a
        // morph. Both guards below stay: the mount is idempotent and the
        // second instance simply bridges to the editor the first mounted.
        if (typeof window.Livewire === 'undefined' || !@this || !window.DotDoc) return;

        const host = this.$refs.editorEl;
        if (!host) return;
        this.owns = !window.DotDoc.get(host);

        // Once per data object: Alpine calls init() more than once.
        if (!this.tabId) {
            this.tabId = window.DotDoc.sync.createTabId({
                crypto: window.crypto, random: Math.random, now: Date.now,
            });
        }

        // Numbers first, editor second: the heading-number decorations are
        // built when the view is created, so seeding the server's outline
        // before mount() is what stops a numbered document rendering
        // unnumbered for one round trip.
        // Only the first call seeds it. Livewire re-inits this element
        // whenever the rendered x-data string changes (every render
        // after the version has moved), and data-outline sits on a
        // wire:ignore element, so it still holds the PAGE-LOAD outline:
        // a later instance would put that back over a newer one.
        if (this.owns) {
            try {
                window.DotDoc.setOutline(JSON.parse(host.dataset.outline || '{}'));
            } catch (_) {}
        }

        // window.DotDoc comes from resources/js/editor/index.js. It owns the
        // 1200ms autosave debounce, the palette, the slash menu and the
        // selection bubble; this component only bridges it to Livewire.
        // mount() is idempotent per element, so a second Alpine instance
        // gets the same editor rather than a second one over the same DOM.
        const handle = window.DotDoc.mount(host, {
            content: @js($contentJson),
            vars: @js($document->variables ?? []),
            pageSetup: @js($outline['pageSetup']),
            headerSegments: @js($outline['headerSegments']),
            footerSegments: @js($outline['footerSegments']),
            // NOT documents.export: that route answers with `attachment`
            // disposition, which every browser aborts inside an <iframe>
            // rather than rendering. documents.preview-pdf is the same
            // PDF with `inline` disposition and its own rate-limit
            // budget, separate from the export/download budget (see
            // DocumentExportController::previewPdf(), .ai/rules/documents-io.md).
            pdfPreviewUrl: '{{ route('documents.preview-pdf', $document->uuid) }}',
            uploadUrl: '{{ route('documents.images.store', $document->uuid) }}',
            // The pagehide/destroy flush POSTs here with navigator.sendBeacon:
            // Livewire cannot issue a request during unload at all.
            autosaveUrl: '{{ route('documents.autosave', $document->uuid) }}',
            csrfToken: document.querySelector('meta[name=csrf-token]').content,
            // The version this page's copy is based on, read at the
            // moment of the unload beacon.
            getBaseVersion: () => this.baseVersion,
            // Whether an overwrite is owed (Keep mine or Put it back,
            // with no save that said so accepted yet), read at the same
            // moment: the beacon then says so too, and the server keeps
            // the version it replaces.
            getOverwrite: () => this.overwriteOwed,
            // Whether text handed to Livewire is unsent and unanswered (its
            // request failed, or the back-off holds it): the beacon then
            // carries it, which the bundle's own bookkeeping would skip.
            getUnsent: () => this.syncHost().unsentAtUnload(),
            // Mounted not editable for somebody who may only read.
            editable: this.editable,
            onChange: (json) => this.persist(json),
            onSelection: (s) => { this.selection = s; this.tick++; },
            onCommand: (name, params) => this.hostCommand(name, params),
        });
        const editor = handle.editor;

        // A render drew editable false (this person's role was lowered
        // since the page opened) but the editor was mounted editable: it
        // is made read only now, on whichever object runs this, and the
        // object that owns the page is told so it can say it in the
        // notice bar (a later object cannot: its fields are not the
        // ones the bar reads).
        if (this.editable === false && !handle.readOnly) {
            handle.makeReadOnly();
            window.dispatchEvent(new CustomEvent('dotdoc:read-only'));
        }

        if (!this.owns) return;

        this.startMetrics();

        window.addEventListener('dotdoc:read-only', () => this.syncHost().readOnlyNoticed());

        // A save the server refuses with 403 means this person may no
        // longer change the document: the page becomes the read only
        // page. A refused Livewire request never settles its promise and
        // Livewire would lay its own error page over the document, so the
        // refusal is caught here, on the request. Which requests count,
        // and what the page does, is decided in sync/host.js.
        this.unhookRefusal = window.Livewire.hook('request', ({ payload, fail }) => {
            if (!this.syncHost().isSaveRequest(payload)) return;
            fail(({ status, preventDefault }) => {
                if (this.syncHost().saveRefused(status)) preventDefault();
            });
        });

        // What the editor holds now is what the server last confirmed,
        // and the status word says Saved (or Read only: fail-closed, or a person who may only read).
        this.syncHost().opened();

        // Typing indicator and the offline draft run off every keystroke;
        // the save itself is debounced inside the bundle.
        editor.on('update', () => {
            this.isTyping = true;
            this.tick++;
            clearTimeout(this.typingTimeout);
            this.typingTimeout = setTimeout(() => { this.isTyping = false; }, 1000);
            // Marks the text unsaved, says Editing and writes the
            // offline draft (never in fail-closed mode).
            this.syncHost().edited();
        });

        this.refreshOutline();
        this.setupEcho();
        // The engine starts only once the draft check has finished. If
        // its first poll brought a newer document before the draft from
        // a previous session had been read, applying that document
        // would delete the draft unexamined.
        this.restoreDraftIfRestorable().finally(() => this.startSync());

        // Online / offline events (dispatched by offline.js initOfflineSupport)
        window.addEventListener('app-offline', () => {
            this.isOffline = true;
            this.report('idle', 'Offline');
        });
        window.addEventListener('app-online',  () => {
            this.isOffline = false;
            // Back online: send what was typed while offline, and ONLY
            // that (an idle reader sends nothing), then check for what
            // was missed. See backOnline() in sync/host.js.
            this.syncHost().backOnline();
        });

        // A hidden tab stops polling; coming back polls at once.
        document.addEventListener('visibilitychange', () => this.syncEngine()?.visibilityChanged());

        // Tell the server this tab is going, so the people left behind
        // stop seeing a face that is no longer here. By beacon, like the
        // unload save: nothing else is delivered from a closing page.
        window.addEventListener('pagehide', () => {
            // The counters go after the editor's own pagehide flush (registered when it was mounted, so it runs first and its beacon takes the shared budget first), and never in the way: a beacon, nothing awaited, nothing thrown.
            try { this.syncMetrics()?.flush({ beacon: true }); } catch (_) {}
            if (typeof navigator.sendBeacon !== 'function') return;
            navigator.sendBeacon(
                '{{ route('documents.sync', $document->uuid) }}',
                new Blob([JSON.stringify({
                    _token: document.querySelector('meta[name=csrf-token]').content,
                    version: this.baseVersion,
                    tab: this.tabId,
                    leaving: true,
                })], { type: 'application/json' })
            );
        });
    },

    // Everything this page decides about a save (send it, hold it, send
    // it again, refuse it, overwrite on purpose), about a newer document
    // (follow it, raise the conflict, set text aside, put it back) and
    // about the offline draft is decided in
    // resources/js/editor/sync/host.js, where node --test runs it
    // together with the real engine. The methods below hand over to it
    // and do nothing else.
    //
    // The host keeps no state of its own. It is built afresh from `this`
    // for every call, because Alpine re-creates this data object whenever
    // a render changes the x-data string, while the handlers bound at
    // first load stay on the first object: a call has to read and write
    // the object it was made on. Besides that object it is given the
    // page: the editor handle and the engine (both parked on the
    // element), Livewire, the draft store, the style element, the clock
    // and the console.
    syncHost() {
        return window.DotDoc.sync.createSyncHost(this, {
            handle: () => window.DotDoc?.get(this.$refs.editorEl),
            engine: () => this.syncEngine(),
            // $wire actions resolve with the PHP method's return value:
            // saveContent() answers {ok, conflict, version}.
            save: (json, baseVersion, overwrite) => @this.saveContent(json, baseVersion, overwrite),
            refreshOutline: () => this.refreshOutline(),
            // The presence strip is rendered by Livewire.
            refreshPresence: () => @this.refreshPresence(),
            // Whether this person may still change the document, from the server.
            canEdit: () => @this.canEdit(),
            report: (tone, word) => this.report(tone, word),
            drafts: () => window.offlineDraft,
            documentsDiffer: (a, b) => window.DotDoc.documentsDiffer(a, b),
            // The outline that arrives with somebody else's document.
            showOutline: (outline) => {
                window.DotDoc.setOutline(outline);
                window.DotDoc.pagination.setPageSetup(
                    outline.pageSetup, outline.headerSegments, outline.footerSegments
                );
            },
            // Somebody else may have changed the document style: the
            // outline carries its numbering and page setup, this carries
            // its fonts and colours.
            showCss: (css) => {
                const style = document.getElementById('doc-style');
                if (style) style.textContent = css;
            },
            confirm: (question) => window.confirm(question),
            now: () => Date.now(),
            info: (...args) => console.info(...args),
            log: (...args) => console.error(...args),
            // What happened, as counts (sync/metrics.js). Nothing comes back and nothing waits on it. Switched
            // off, there is no env.metric at all, so the host does no extra work for a counter nobody keeps.
            metric: this.metricsOn ? (kind, detail) => this.syncMetrics()?.record(kind, detail) : undefined,
        });
    },

    persist(json, options) { return this.syncHost().persist(json, options); },
    resendIfOwed() { this.syncHost().resendIfOwed(); },
    clearDraftIfSettled(snapshot) { return this.syncHost().clearDraftIfSettled(snapshot); },
    syncState() { return this.syncHost().syncState(); },
    adoptVersion(version, options) { this.syncHost().adoptVersion(version, options); },
    applyFromSync(remote, options) { return this.syncHost().applyFromSync(remote, options); },
    enterConflict(version) { this.syncHost().enterConflict(version); },
    keepMine() { this.syncHost().keepMine(); },
    loadTheirs() { return this.pressed(this.syncHost().loadTheirs()); },
    putBack() { return this.pressed(this.syncHost().putBack()); },
    discardSetAside() { return this.pressed(this.syncHost().discardSetAside()); },
    putBackLost(index, fromKeyboard) { return this.pressed(this.syncHost().putBackLost(index, { focus: fromKeyboard === true })); },
    putBackAllLost(fromKeyboard) { return this.pressed(this.syncHost().putBackAllLost({ focus: fromKeyboard === true })); },
    dismissLost() { return this.pressed(this.syncHost().dismissLost()); },
    membersChanged(members) { this.syncHost().membersChanged(members); },
    restoreDraftIfRestorable() { return this.syncHost().restoreDraft(); },
    applySuggestion(content, version) { this.syncHost().applySuggestion(content, version); },

    // Numbering rules live in the document style, so the server owns them.
    // Pull the fresh numbers after every save and hand them to the bundle.
    refreshOutline() {
        return @this.outline().then((outline) => {
            if (!outline) return;
            window.DotDoc.setOutline(outline);
            window.DotDoc.pagination.setPageSetup(outline.pageSetup, outline.headerSegments, outline.footerSegments);
        });
    },

    // The engine is parked on the editor element, not in Alpine's
    // reactive data, for the same reason the editor handle is.
    syncEngine() {
        return this.$refs.editorEl?.__dotdocSync ?? null;
    },

    // The co-editing counters, parked on the element for the same reason
    // (and so every data object reaches the same one). Null when the
    // server switched counting off. The first flush (about 5 s after the
    // page opened, so a short visit still records) and the once-a-minute
    // flush are ordinary POSTs; only the flush on leaving is a beacon, with
    // the token in the body as the other unload beacons have it, and
    // declared as JSON, which the CSRF check and the controller both need
    // to find the token and the counts in it. Whatever goes wrong with any
    // of them is dropped by the module. Opening the page is counted here,
    // once, by the object that built the counters: solo and read only
    // sessions included, with nothing else attached.
    syncMetrics() {
        return this.$refs.editorEl?.__dotdocMetrics ?? null;
    },

    startMetrics() {
        const host = this.$refs.editorEl;
        if (!this.metricsOn || !host || host.__dotdocMetrics || !window.DotDoc?.sync?.createMetrics) return;

        const url = '{{ route('documents.sync-metrics', $document->uuid) }}';
        host.__dotdocMetrics = window.DotDoc.sync.createMetrics({
            now: () => Date.now(),
            token: () => document.querySelector('meta[name=csrf-token]').content,
            send: (body) => fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body,
            }).then((response) => response.ok),
            beacon: (body) => typeof navigator.sendBeacon === 'function'
                && navigator.sendBeacon(url, new Blob([body], { type: 'application/json' })),
            schedule: (run, ms) => setTimeout(run, ms),
        });
        host.__dotdocMetrics.record('open');
    },

    startSync() {
        const host = this.$refs.editorEl;
        if (!host || host.__dotdocSync || !window.DotDoc?.sync) return;

        host.__dotdocSync = window.DotDoc.sync.createSyncEngine({
            version: this.baseVersion,
            tab: this.tabId,
            request: window.DotDoc.sync.createSyncRequest(
                '{{ route('documents.sync', $document->uuid) }}',
                document.querySelector('meta[name=csrf-token]').content
            ),
            visible: () => document.visibilityState !== 'hidden',
            // What the engine may do with a newer document, and what the
            // page does with what a poll brings, is decided in
            // sync/host.js. The engine keeps these callbacks for as long
            // as it lives, so they stay with the data object that
            // started it.
            host: this.syncHost().engineHost(),
        });

        host.__dotdocSync.start();
    },

    // Every name here is a registry command in the `system` group: the
    // editor cannot do these on its own, so the page does them. Nothing is
    // routed that has no destination — the palette carries no entry whose
    // only behaviour would be to fall off the end of this function.
    hostCommand(name, params) {
        const exports = {
            'export.pdf': '{{ route('documents.export', [$document->uuid, 'pdf']) }}',
            'export.word': '{{ route('documents.export', [$document->uuid, 'word']) }}',
            'export.html': '{{ route('documents.export', [$document->uuid, 'html']) }}',
            'export.markdown': '{{ route('documents.export', [$document->uuid, 'markdown']) }}',
            'share': '{{ route('documents.share', $document->uuid) }}',
            'recent.open': '{{ route('documents.index') }}',
        };

        if (exports[name]) {
            window.location.href = exports[name];
        } else if (name === 'search') {
            // The DocumentSearch-backed box is the documents ledger's. A
            // selection travels with the command as `q`, which Index reads
            // off the query string; `focus=search` puts the cursor in that
            // box on arrival. Without it, searching with nothing selected
            // landed on exactly the page `recent.open` lands on, with
            // nothing to type into — two palette rows, one destination.
            const term = (params && params.term) || '';
            window.location.href = '{{ route('documents.index') }}?focus=search' +
                (term ? '&q=' + encodeURIComponent(term) : '');
        } else if (name === 'ai' && params && params.action) {
            // The assistant lives in the dock, which starts collapsed on
            // this route — asking it something has to open it. The one
            // other control that reaches the assistant from outside the
            // dock — Ask the assistant, in the More menu below — does the
            // same thing with a data-shell-expand hook; a palette command
            // has no button to hang one on, so the window event shell.js
            // also listens for is this path's trigger.
            //
            // (Comments are NOT one of these: they render beside the
            // paper, not in the dock. See the toggle in that same menu.)
            window.dispatchEvent(new CustomEvent('shell:reveal-dock'));
            Livewire.dispatchTo('documents.ai-assistant', 'ai-action', {
                action: params.action,
                param: params.param || '',
            });
        } else if (name === 'style.switch') {
            // The picker sends a key; the palette sends nothing at all
            // (`run(editor, name)` passes no params), and a branch that
            // only answered the first case made the style row in ⌘K a row
            // that silently did nothing. With no key the command's
            // destination is the picker itself.
            if (params && params.key) {
                @this.setStyle(params.key);
            } else {
                const picker = document.getElementById('doc-style-picker');
                if (picker) {
                    picker.focus();
                    // Chrome drops the list open outright; where it is not
                    // supported (or the gesture does not carry), the focus
                    // ring on the picker is the answer on its own.
                    try { picker.showPicker(); } catch (ignored) { /* no user gesture */ }
                }
            }
        } else if (name === 'comment') {
            if (!@this.commentSidebarOpen) @this.toggleCommentSidebar();
        }
    },

    setupEcho() {
        if (typeof window.Echo === 'undefined') {
            // Loaded after the page when the build has a Reverb key.
            window.addEventListener('echo:ready', () => this.setupEcho(), { once: true });
            return;
        }

        this.echo = window.Echo.join('document.{{ $document->id }}')
            .here((users) => {
                // Initial member list from presence channel
            })
            .joining((user) => {
                console.log(user.name + ' joined');
            })
            .leaving((user) => {
                console.log(user.name + ' left');
            })
            .listen('.document.updated', () => {
                // If a socket happens to be connected (local development
                // with Reverb), a broadcast means one thing only: check
                // now. The sync engine is what applies a document.
                this.syncEngine()?.poke();
            })
            .listen('.comment.posted', (e) => {
                Livewire.dispatch('comment-posted', e);
            });
    },

    destroy() {
        // Alpine also runs this on the OLD data object each time Livewire
        // morphs a changed x-data string onto this element, which is
        // every render after the document version has moved. The editor
        // and the sync engine live on the element and must survive
        // that; only a real removal tears them down.
        if (this.$el && this.$el.isConnected) return;
        clearTimeout(this.typingTimeout);
        if (this.unhookRefusal) this.unhookRefusal();
        this.syncEngine()?.stop();
        if (this.$refs.editorEl) this.$refs.editorEl.__dotdocSync = null;
        // Echo.join() hands back the CHANNEL, which has no leave() of its
        // own — leaving is done on the Echo instance, by name. Calling
        // this.echo.leave() threw, and Alpine's error report (which
        // includes the element) was then serialised by the browser
        // logger, walking el.__livewire.$wire and firing a bogus
        // `toJSON` Livewire request that 500s.
        if (this.echo && window.Echo) {
            window.Echo.leave('document.{{ $document->id }}');
        }
        this.echo = null;
        // Only the instance that mounted the editor tears it down.
        if (this.owns) {
            window.DotDoc?.get(this.$refs.editorEl)?.destroy();
        }
        // The counters leave by beacon AFTER the editor's own destroy, which flushes the unsaved text by beacon:
        // the browser shares one 64 KiB budget between a page's beacons, and the document comes first.
        try { this.syncMetrics()?.flush({ beacon: true }); } catch (_) {}
        if (this.$refs.editorEl) this.$refs.editorEl.__dotdocMetrics = null;
    },

    // Apply an AI result to the editor.
    //
    // The payload is raw model HTML. It NEVER goes to setContent()
    // directly: with enableContentCheck on, one tag this schema does not
    // know throws, and the throw comes out of an Alpine handler with
    // nothing to catch it — the writer sees a broken page. applyHtml()
    // parses it, checks it against the live schema, and returns false
    // (document untouched) when it cannot be used.
    applyAiContent(type, content) {
        const handle = window.DotDoc?.get(this.$refs.editorEl);
        if (!handle) return;

        if (!handle.applyHtml(content, { mode: type === 'replace' ? 'replace' : 'insert' })) {
            this.aiError = 'That AI result could not be applied — the document is unchanged.';
            return;
        }

        this.aiError = '';
        this.tick++;
        handle.flush();
    },

    // Insert voice-transcribed text at current cursor position
    insertVoiceText(text) {
        const editor = this.ed();
        if (!editor || !text) return;
        editor.commands.focus();
        editor.commands.insertContent(text + ' ');
    }
}