<div
    x-data="{
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
                onChange: (json) => this.persist(json),
                onSelection: (s) => { this.selection = s; this.tick++; },
                onCommand: (name, params) => this.hostCommand(name, params),
            });
            const editor = handle.editor;

            if (!this.owns) return;

            // What the editor holds now is what the server last confirmed,
            // and the status word says Saved (or Read only, fail-closed).
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
        loadTheirs() { return this.syncHost().loadTheirs(); },
        putBack() { this.syncHost().putBack(); },
        discardSetAside() { this.syncHost().discardSetAside(); },
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
            if (typeof window.Echo === 'undefined') return;

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
    }"
    x-init="init()"
    x-destroy="destroy()"
    @ai-apply.window="applyAiContent('replace', $event.detail.content)"
    @suggestion-accepted.window="applySuggestion($event.detail.content, $event.detail.version)"
    @voice-transcript.window="insertVoiceText($event.detail.text)"
    @style-changed.window="document.getElementById('doc-style').textContent = $event.detail.css; adoptVersion($event.detail.version); refreshOutline()"
    @keydown.ctrl.shift.k.window.prevent="$dispatch('open-ai-palette')"
    @keydown.meta.shift.k.window.prevent="$dispatch('open-ai-palette')"
    class="editor"
>
    <style id="doc-style">{!! $styleCss !!}</style>

    {{-- AI Components (outside toolbar, at root level) --}}
    {{-- ai-chat is NOT here: the assistant renders inside the dock's
         Intelligence tab (resources/views/components/shell/dock.blade.php).
         Nothing floats over the desk. --}}
    <livewire:documents.ai-assistant :document="$document" wire:key="ai-assistant" lazy />
    <livewire:documents.save-as-template :document="$document" wire:key="save-as-template" lazy />

    {{-- Listen for Ctrl+K to open AI palette --}}
    <div x-data
         @open-ai-palette.window="Livewire.dispatchTo('documents.ai-assistant', 'open-palette')"
         @open-save-as-template.window="Livewire.dispatchTo('documents.save-as-template', 'open')"
         class="hidden"></div>
    {{-- ── The head of the editor page ──────────────────────────────────
         The persistent bar and, directly under it, the notice bar. One
         element, so the two stay at the top of the canvas TOGETHER where
         the page scrolls under them (below 900px): a notice that asks the
         writer to choose must not scroll away with the paper. --}}
    <div class="doc-head">
        {{-- ── The persistent bar ───────────────────────────────────────────
             ONE slim row under the top bar, and nothing on it inserts a block.
             Spec §4 retired the bench: the twelve formatting buttons that used to
             wrap into three ragged rows are now either on the floating toolbar
             that follows the selection (marks, heading level, table and image
             tools — resources/js/editor/ui/bubble.js) or in the two menus that
             already listed them, `/` and ⌘K.

             What is left is what has to be true all the time: what the document is
             called, which style it is set in, where it is filed, who else is here,
             whether it is saved, and which version that is. The ⌘K button is the
             door to everything else, and "More" holds the actions that act on the
             whole document rather than on the text under the cursor.

             The document's title is here, and ONLY here: the top bar deliberately
             does not repeat it on this route (layouts/app.blade.php), because a
             title you can read in two places but edit in one is a title people
             edit in the wrong one. --}}
        <div class="doc-bar">
            {{-- The page's one <h1>. The title is edited through the input
                 beside it, so the heading is for the document outline and for
                 assistive technology; Livewire re-renders both together. --}}
            <h1 class="sr-only">{{ $title ?: 'Untitled' }}</h1>

            <label class="sr-only" for="doc-title">Document title</label>
            <input id="doc-title"
                   wire:model.blur="title"
                   wire:change="saveTitle"
                   type="text"
                   class="doc-title-field"
                   placeholder="Untitled" />

            <label class="sr-only" for="doc-style-picker">Document style</label>
            <select id="doc-style-picker" wire:change="setStyle($event.target.value)" class="tool-select">
                @foreach (\App\Styles\StyleEngine::systemKeys() as $styleKey)
                    <option value="{{ $styleKey }}" @selected($document->style_key === $styleKey)>{{ ucfirst($styleKey) }}</option>
                @endforeach
            </select>
            @error('style')
                <span class="field-error">{{ $message }}</span>
            @enderror

            <label class="sr-only" for="doc-view-mode">Page view</label>
            <select id="doc-view-mode" class="tool-select"
                    x-model="viewMode" @change="window.DotDoc.pagination.setMode(viewMode)">
                <option value="continuous">Continuous</option>
                <option value="single">Single page</option>
                <option value="multi-page">Multi-page</option>
                <option value="focus">Focus</option>
                <option value="print-preview">Print preview</option>
            </select>

            <button type="button" class="tool tool-mono" aria-pressed="false"
                    x-bind:aria-pressed="thumbnailsOpen ? 'true' : 'false'"
                    @click="thumbnailsOpen = !thumbnailsOpen; if (thumbnailsOpen) $nextTick(() => window.DotDoc.pagination.refreshThumbnails())">Pages</button>

            {{-- Everything structural — headings, lists, tables, images, callouts,
                 columns, breaks, cross-references, exports, the assistant — is in
                 the registry, which this button and the `/` menu both list. --}}
            <button type="button" class="tool tool-mono doc-bar-palette"
                    title="Commands — or type / in the document" aria-keyshortcuts="Meta+K Control+K"
                    @click="window.DotDoc.openPalette(ed())">&#8984;K</button>

            {{-- Where this document is filed in the shared Dot.Files tree.
                 A quiet line of text, not a link: the button beside it is the one
                 affordance, and it opens the same .sheet folder picker the
                 documents index uses for rename. --}}
            <span class="micro doc-bar-filed" aria-label="Filed in">{{ collect($this->locationCrumbs)->map(fn ($crumb) => $crumb->name())->join(' / ') ?: 'Unfiled' }}</span>
            <button type="button" class="tool tool-mono" x-ref="moveTrigger"
                    wire:click="$set('showMoveSheet', true)">Move</button>
            @error('location')
                <span class="field-error">{{ $message }}</span>
            @enderror

            @if (count($activeUsers) > 0)
                <div class="presence" aria-label="People here now">
                    @foreach (array_slice($activeUsers, 0, 4) as $member)
                        <span class="presence-face" title="{{ $member['name'] }}">
                            @if (! empty($member['avatar']))
                                <img src="{{ $member['avatar'] }}" alt="{{ $member['name'] }}" />
                            @else
                                {{ strtoupper(substr($member['name'], 0, 1)) }}
                            @endif
                        </span>
                    @endforeach
                    @if (count($activeUsers) > 4)
                        <span class="presence-face">+{{ count($activeUsers) - 4 }}</span>
                    @endif
                </div>
            @endif

            {{-- State is a WORD and a dot, never colour alone. A rejected save
                 has to be visible: the writer keeps typing over content the
                 server never accepted, and the offline draft is deliberately
                 kept as the only remaining copy. The same words go to the
                 top bar through the `shell:save-state` event. --}}
            <span class="doc-status" aria-live="polite">
                <x-shell.status-word tone="idle" word="Offline" x-show="isOffline"
                                     title="Edits are saved in this browser and sync when you are back online." />

                <x-shell.status-word tone="idle" word="Editing" x-show="isTyping && !isOffline" />

                <x-shell.status-word tone="idle" word="Saving"
                                     wire:loading wire:target="saveContent,saveTitle" />

                {{-- wire:ignore: the sentence is written by Alpine (x-text),
                     and a Livewire morph evaluates x-text on the incoming
                     copy of this element against the newest data object,
                     where aiError is empty, then removes the live text: the
                     word stayed up with nothing in it. Nothing here is
                     rendered by the server, so the morph can skip it
                     (.ai/rules/livewire.md). --}}
                <span class="status-word status-word-danger" wire:ignore x-show="aiError" x-cloak
                      @click="aiError = ''" style="cursor:pointer" title="Click to dismiss">
                    <span class="status-word-dot" aria-hidden="true"></span>
                    <span x-text="aiError"></span>
                </span>

                {{-- A newer version was saved elsewhere while this tab held
                     unsaved typing, and saving is suspended until the writer
                     chooses. The strip says so in two words. The sentence and
                     the two buttons are in the notice bar under this row: the
                     strip is one line that does not wrap, and a notice with
                     buttons in it ended up outside the window or under the
                     dock. Rendered on every render and shown with x-show:
                     x-show is the one Alpine binding a Livewire morph leaves
                     as it is on the live element (.ai/rules/livewire.md). --}}
                <x-shell.status-word tone="danger" word="Not saved" x-show="conflict" x-cloak />

                @error('content')
                    <x-shell.status-word tone="danger" :title="$message"
                                         :word="'Not saved — '.\Illuminate\Support\Str::limit($message, 60)" />
                @enderror

                {{-- The x-show sits on a wrapper that is rendered the same on
                     EVERY render, and the Saved / Ready word inside it comes and
                     goes with the error above. With the x-show on the word
                     itself, a word that came back after a rejected save was a
                     new element, bound to the newest Alpine data object, whose
                     conflict, syncNotice and isTyping never change
                     (.ai/rules/livewire.md): Saved then showed beside the
                     conflict notice and while typing.

                     `!unsaved`: the word inside is whatever the server last
                     rendered, so it would say Saved whenever the writer
                     paused. `unsaved` is the page's own knowledge that the
                     editor holds text no accepted save has stored (sync/host.js),
                     and Saved is not said over such text: not after a save
                     that failed or never answered, and not in the moment
                     between the end of typing and the autosave. The strip
                     then shows no word at all until the save is sent
                     (Saving), stored (Saved) or refused (Not saved); the top
                     bar keeps its own word throughout. An edit undone again
                     clears the flag on the next poll that completes
                     (settleIfBackAtConfirmed() in sync/host.js). --}}
                <span x-show="!isTyping && !isOffline && !conflict && !syncNotice && !unsaved">
                    @unless ($errors->has('content'))
                        <x-shell.status-word tone="good" :word="$saved ? 'Saved' : 'Ready'"
                                             wire:loading.remove wire:target="saveContent,saveTitle" />
                    @endunless
                </span>

                <span class="micro" title="Last edited {{ $document->updated_at->diffForHumans() }}">
                    <x-shell.figure :value="$document->version" prefix="v" label="Version" />
                </span>
            </span>

            {{-- Everything that acts on the whole document, in one menu, so the
                 row never has to reflow. --}}
            <div class="menu doc-bar-end" x-data="{ open: false }"
                 x-on:keydown.escape.window="if (open) { open = false; $refs.moreBtn.focus() }">
                <button type="button" class="tool tool-mono" x-ref="moreBtn" @click="open = !open"
                        :aria-expanded="open ? 'true' : 'false'">More</button>

                <div class="menu-list menu-list-wide" x-show="open" @click.outside="open = false" x-cloak>
                    <button type="button" data-shell-expand="dock"
                            @click="$dispatch('open-ai-palette'); open = false">
                        Ask the assistant
                        <span class="micro">Ctrl+Shift+K</span>
                    </button>

                    <button type="button" wire:click="toggleSuggestionMode"
                            aria-pressed="{{ $suggestionMode ? 'true' : 'false' }}">
                        {{ $suggestionMode ? 'Leave suggesting mode' : 'Suggest instead of editing' }}
                    </button>

                    {{-- No `data-shell-expand` here, deliberately: comments render
                         in `.editor-side`, beside the paper, NOT in the dock.
                         Revealing a panel is one-way by design, so pointing this at
                         the dock took ~340px of canvas width in either direction
                         with no way back. --}}
                    <button type="button" wire:click="toggleCommentSidebar"
                            aria-pressed="{{ $commentSidebarOpen ? 'true' : 'false' }}">
                        {{ $commentSidebarOpen ? 'Hide comments' : 'Show comments' }}
                    </button>

                    <button type="button" @click="ed().chain().focus().undo().run(); open = false">
                        Undo
                        <span class="micro">&#8984;Z</span>
                    </button>
                    <button type="button" @click="ed().chain().focus().redo().run(); open = false">
                        Redo
                        <span class="micro">&#8679;&#8984;Z</span>
                    </button>

                    <button type="button"
                            :class="ed()?.isActive('code') ? 'is-on' : ''"
                            @click="ed().chain().focus().toggleCode().run(); open = false">Inline code</button>

                    <div x-data="voiceTyping" x-init="init()">
                        <button type="button" x-show="supported" @click="toggle()"
                                :aria-pressed="listening ? 'true' : 'false'"
                                x-text="listening ? 'Stop voice typing' : 'Start voice typing'">Start voice typing</button>
                    </div>

                    <span class="menu-label">Export</span>
                    <a href="{{ route('documents.export', [$document->uuid, 'pdf']) }}">PDF</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'word']) }}">Word (.docx)</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'html']) }}">HTML</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'markdown']) }}">Markdown</a>

                    {{-- The same render, filed beside the document in the
                         shared tree instead of downloaded. --}}
                    <span class="menu-label">Save to Dot.Files</span>
                    {{-- A bare <form>/<button>, NOT .menu-form/.btn: those are
                         for the import picker, and their centred full-width
                         button breaks the menu's row rhythm beside the export
                         links above. `.menu-list button` already styles this. --}}
                    @foreach (['pdf' => 'PDF', 'word' => 'Word (.docx)', 'html' => 'HTML', 'markdown' => 'Markdown'] as $format => $label)
                        <form action="{{ route('documents.export.save-to-files', [$document->uuid, $format]) }}" method="POST">
                            @csrf
                            <button type="submit">{{ $label }}</button>
                        </form>
                    @endforeach

                    <span class="menu-label">Import</span>
                    <form action="{{ route('documents.import', $document->uuid) }}" method="POST"
                          enctype="multipart/form-data" class="menu-form">
                        @csrf
                        <label class="field-label" for="doc-import">A .docx or .md file</label>
                        <input id="doc-import" type="file" name="file" accept=".docx,.md,.markdown,.txt" class="field" />
                        <button type="submit" class="btn btn-primary">Import it</button>
                    </form>

                    <button type="button" @click="$dispatch('open-save-as-template'); open = false"
                            title="Save this document as a reusable template">Save as a template</button>
                </div>
            </div>
        </div>

        {{-- ── The notice bar ───────────────────────────────────────────────
             What the writer has to read in full, and what they have to
             answer, is said here and not in the one-line status strip above:
             this bar is as wide as the editor column, its text wraps, and
             its buttons drop onto a line of their own when there is no room
             beside the text. It takes no space while nothing is showing.

             It is a live region, so a notice is announced when it appears.

             The whole bar is wire:ignore, and its rows are switched with the
             `hidden` attribute (x-bind:hidden), not with x-show. Both for a
             reason (.ai/rules/livewire.md):

             - Every Livewire morph initialises the INCOMING copy of each
               element it patches against the root's newest Alpine data
               object, whose conflict, setAside and syncNotice never change,
               and copies the outcome onto the live element. Only x-show is
               guarded against that. A render while the conflict notice
               showed disabled Load theirs, and one while a sync notice
               showed took its sentence away for good. Livewire skips a
               wire:ignore element before it clones anything, so nothing in
               here is evaluated or patched by a morph. The price: nothing
               in here may be rendered by the server. No Blade condition, no
               echoed value. It is static markup that Alpine shows, hides
               and fills.
             - After its first evaluation x-show applies a change (a hide or
               a show) only inside requestAnimationFrame while the page
               reports itself visible. In a tab that is not being painted
               the frame never comes: a notice stayed up although its state
               was gone, and one that should have appeared would not have.
               x-bind:hidden writes the attribute in Alpine's own flush
               after the change and waits for no frame. The rows carry
               `hidden` in the markup, so nothing shows before Alpine runs
               (`.doc-notice[hidden]` in shell.css: the row is display:flex,
               which beats the browser's own [hidden] rule).
               Do NOT use x-bind:hidden on anything outside a wire:ignore
               element: without the x-show guard a morph writes the newest
               object's answer straight onto the live element. --}}
        <div class="doc-notices" role="status" aria-live="polite" wire:ignore>
            {{-- A newer version was saved elsewhere while this tab held
                 unsaved typing. Saving is suspended until the writer picks
                 one: nothing is overwritten and nothing is thrown away
                 without being asked. Reloading instead of choosing opens
                 the newer version and sets this tab's text aside: the page
                 then offers Put it back, but only in a browser that could
                 keep the offline draft. Hence the last sentence. --}}
            <div class="doc-notice" hidden x-bind:hidden="!conflict">
                <p class="doc-notice-text">
                    <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span>
                    <span>Not saved — this document was changed elsewhere while you were typing. Do not reload: choose one.</span>
                </p>
                <span class="doc-notice-actions">
                    <button type="button" class="tool tool-mono" @click="keepMine()"
                            title="Save your version over the newer one. The other version is kept in the history.">Keep mine</button>
                    <button type="button" class="tool tool-mono" @click="loadTheirs()"
                            :disabled="!conflict || !conflict.ready"
                            title="Show the newer version. You can put your text back afterwards.">Load theirs</button>
                </span>
            </div>

            {{-- The writer chose Load theirs, or opened the page with a draft
                 the document had moved past. Their own text is held by the
                 page so they can have it back. --}}
            <div class="doc-notice" hidden x-bind:hidden="!setAside">
                <p class="doc-notice-text">
                    <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
                    <span>Your text was set aside.</span>
                </p>
                <span class="doc-notice-actions">
                    <button type="button" class="tool tool-mono" @click="putBack()">Put it back</button>
                </span>
            </div>

            {{-- The page can no longer stay in step: signed out, access
                 removed, the document gone, or a version this editor cannot
                 open. --}}
            <div class="doc-notice" hidden x-bind:hidden="!syncNotice">
                <p class="doc-notice-text">
                    <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span>
                    <span x-text="syncNotice"></span>
                </p>
            </div>
        </div>
    </div>

    {{-- Pending suggestions: the assistant's ink, still in marker. --}}
    @if (count($pendingSuggestions) > 0)
        <section class="panel" aria-labelledby="pending-suggestions" style="border-radius:0">
            <div class="panel-head">
                <h2 class="section-title" id="pending-suggestions">
                    Waiting for you — <x-shell.figure :value="count($pendingSuggestions)" label="Suggestions waiting" />
                </h2>
                <span class="micro">Marker becomes graphite once accepted</span>
            </div>
            <ul class="list">
                @foreach ($pendingSuggestions as $suggestion)
                    <li class="list-row">
                        <x-shell.status-word tone="good" word="In marker" />
                        <span class="list-key">
                            {{ $suggestion['excerpt'] }}
                            <span class="list-sub">{{ $suggestion['user'] }} · {{ $suggestion['created_at'] }}</span>
                        </span>
                        <button type="button" class="btn btn-sm" wire:click="acceptSuggestion({{ $suggestion['id'] }})">Accept</button>
                        <button type="button" class="btn btn-sm" wire:click="rejectSuggestion({{ $suggestion['id'] }})">Drop</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="editor-row">
        {{-- wire:ignore keeps Livewire's DOM morph out of the ProseMirror
             subtree, which it did not render and must not diff. data-outline
             seeds the numbering before the first save round trip. The outer
             wire:ignore on .editor-main protects the pagination DOM siblings
             of #doc-paper (the Multi-Page grid, the Print Preview iframe)
             that Livewire's own render never produced - without it, the
             next morph would strip them as extra nodes. --}}
        <div class="editor-main" wire:ignore>
            <div id="doc-paper" x-ref="editorEl" wire:ignore class="canvas" data-outline="{{ json_encode($outline) }}"></div>
        </div>

        {{-- Populated by the "Pages" button's @click above the moment the
             panel opens (pagination.refreshThumbnails()), and kept current
             after that by every repagination pass while it stays open
             (see refreshRailIfVisible() in pagination/index.js) - not by
             any init hook here, since this div is already in the DOM
             (just hidden) when Alpine initialises, well before the writer
             ever opens it. --}}
        <div class="editor-thumbnails" x-show="thumbnailsOpen" x-cloak
             aria-label="Page thumbnails">
            <div class="dotdoc-thumbnail-rail" wire:ignore></div>
        </div>

        @if ($commentSidebarOpen)
            <div class="editor-side">
                @livewire('documents.comment-thread', ['document' => $document], key('comment-thread'))
            </div>
        @endif
    </div>

    {{-- Move: the same .sheet pattern as the documents index's rename -
         Escape closes it and returns focus to the control that opened it.
         A folder picker rather than drag-and-drop, so it is keyboard-
         operable from the first commit rather than as a fallback. --}}
    @if ($showMoveSheet)
        <div class="scrim" wire:click.self="$set('showMoveSheet', false)" role="dialog" aria-modal="true"
             aria-labelledby="doc-move-title"
             x-on:keydown.escape.window="$wire.set('showMoveSheet', false); $refs.moveTrigger && $refs.moveTrigger.focus()">
            <div class="sheet">
                <div class="sheet-head">
                    <h2 class="h-panel" id="doc-move-title">File this document in</h2>
                </div>
                <div class="sheet-body">
                    @error('location')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                    <ul class="list">
                        @foreach ($this->folderChoices as $choice)
                            <li wire:key="move-{{ $choice['uuid'] }}">
                                <button type="button" class="list-row" style="width:100%;text-align:left"
                                        wire:click="moveTo({{ $choice['id'] }})">
                                    <span class="list-key">{{ $choice['label'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="sheet-foot">
                    <button type="button" class="btn" wire:click="$set('showMoveSheet', false)">Cancel</button>
                </div>
            </div>
        </div>
    @endif
</div>
