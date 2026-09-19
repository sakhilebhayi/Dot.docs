<div
    x-data="{
        owns: false,
        echo: null,
        heartbeatTimer: null,
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

            // Numbers first, editor second: the heading-number decorations are
            // built when the view is created, so seeding the server's outline
            // before mount() is what stops a numbered document rendering
            // unnumbered for one round trip.
            try {
                window.DotDoc.setOutline(JSON.parse(host.dataset.outline || '{}'));
            } catch (_) {}

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
                pdfPreviewUrl: '{{ route('documents.export', [$document->uuid, 'pdf']) }}',
                uploadUrl: '{{ route('documents.images.store', $document->uuid) }}',
                // The pagehide/destroy flush POSTs here with navigator.sendBeacon:
                // Livewire cannot issue a request during unload at all.
                autosaveUrl: '{{ route('documents.autosave', $document->uuid) }}',
                csrfToken: document.querySelector('meta[name=csrf-token]').content,
                onChange: (json) => this.persist(json),
                onSelection: (s) => { this.selection = s; this.tick++; },
                onCommand: (name, params) => this.hostCommand(name, params),
            });
            const editor = handle.editor;

            if (!this.owns) return;

            // Typing indicator and the offline draft run off every keystroke;
            // the save itself is debounced inside the bundle.
            editor.on('update', () => {
                this.isTyping = true;
                this.tick++;
                this.report('idle', 'Editing');
                clearTimeout(this.typingTimeout);
                this.typingTimeout = setTimeout(() => { this.isTyping = false; }, 1000);
                // Never write a draft in fail-closed mode: what the editor is
                // holding then is not the document.
                if (window.offlineDraft && handle.autosaves !== false) {
                    window.offlineDraft.saveDraft(this.docUuid, JSON.stringify(editor.getJSON()), this.baseVersion);
                }
            });

            this.report(handle.autosaves === false ? 'danger' : 'good',
                        handle.autosaves === false ? 'Read only' : 'Saved');

            this.refreshOutline();
            this.restoreDraftIfRestorable();
            this.setupEcho();

            // Online / offline events (dispatched by offline.js initOfflineSupport)
            window.addEventListener('app-offline', () => {
                this.isOffline = true;
                this.report('idle', 'Offline');
            });
            window.addEventListener('app-online',  () => {
                this.isOffline = false;
                // Flush the current document now that we are back online. The
                // draft is NOT cleared here: persist() clears it itself, and
                // only once the save has actually stored what the editor is
                // holding. Clearing it alongside an un-awaited save was how a
                // failed reconnect save lost the offline work outright.
                this.persist(editor.getJSON());
            });

            // Heartbeat every 60 seconds to keep presence alive. It
            // re-arms itself with setTimeout rather than running on a repeating
            // timer, so a slow round trip cannot stack beats on top of each
            // other, and destroy() only ever has one handle to clear.
            const beat = () => {
                this.heartbeatTimer = setTimeout(() => {
                    @this.heartbeat();
                    beat();
                }, 60000);
            };
            beat();

            // Notify server when tab/window is closed
            window.addEventListener('beforeunload', () => {
                @this.leaving();
            });
        },

        // $wire actions resolve with the PHP method's return value, so a
        // rejected save (DocumentSchema validation) is visible here. On a
        // reject the offline draft is KEPT — it is the only remaining copy of
        // what the writer typed — and the error renders in the status area.
        // saveContent() answers {ok, version}: `version` becomes the base the
        // next draft is written against.
        persist(json) {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            // Fail-closed (the content check refused the document): the editor
            // is read-only and must not write anything back.
            if (handle && handle.autosaves === false) return Promise.resolve();

            this.report('idle', 'Saving');

            // What is being SENT, captured now. Saves resolve out of order, so
            // an older one must not be allowed to clear a draft that protects
            // newer keystrokes.
            const snapshot = JSON.stringify(json);

            return @this.saveContent(json).then((result) => {
                if (result && Number.isFinite(result.version)) {
                    this.baseVersion = result.version;
                }
                if (result && result.ok) {
                    this.clearDraftIfSettled(snapshot);
                    this.report('good', 'Saved');
                } else {
                    this.report('danger', 'Not saved');
                }

                return this.refreshOutline();
            });
        },

        // Drop the offline draft only when the document the server just
        // stored is still exactly what the editor holds AND nothing further
        // is queued. Anything else means the draft is still the only copy of
        // something.
        clearDraftIfSettled(snapshot) {
            if (!window.offlineDraft) return;
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            if (!handle || handle.autosaves === false) return;
            if (handle.pending) return;
            if (JSON.stringify(handle.editor.getJSON()) !== snapshot) return;

            window.offlineDraft.clearDraft(this.docUuid);
        },

        // Numbering rules live in the document style, so the server owns them.
        // Pull the fresh numbers after every save and hand them to the bundle.
        refreshOutline() {
            return @this.outline().then((outline) => {
                if (!outline) return;
                window.DotDoc.setOutline(outline);
                window.DotDoc.pagination.setPageSetup(outline.pageSetup, outline.headerSegments, outline.footerSegments);
            });
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

        // loadDraft returns {json, savedAt, baseVersion}. A draft is offered
        // only when it was written against THIS version of the document —
        // if the version has moved on, somebody else has saved since and
        // restoring the draft would overwrite them.
        async restoreDraftIfRestorable() {
            if (!window.offlineDraft) return;
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            // Fail-closed: the editor is read-only and holds something that is
            // not the document, so neither restore a draft nor delete one.
            if (!handle || handle.autosaves === false) return;
            const editor = handle.editor;

            try {
                const draft = await window.offlineDraft.loadDraft(this.docUuid);
                if (!draft) return;

                const parsed = JSON.parse(draft.json);
                if (!parsed || parsed.type !== 'doc' || draft.baseVersion === null) {
                    window.offlineDraft.clearDraft(this.docUuid);
                    return;
                }

                if (draft.baseVersion < this.documentVersion) {
                    // Not restorable, but not this page's to destroy either.
                    // Park it under a stale- key so it can be recovered by
                    // hand; parkStaleDraft() stamps parkedAt, and the sweep on
                    // the next app boot collects it after 7 days.
                    await window.offlineDraft.parkStaleDraft(this.docUuid, draft.json, draft.baseVersion);
                    window.offlineDraft.clearDraft(this.docUuid);
                    console.info(
                        '[Dot.Doc] An offline draft based on v' + draft.baseVersion +
                        ' was kept as stale-' + this.docUuid + ': the document is now at v' +
                        this.documentVersion + ', so restoring it would overwrite a newer save.'
                    );
                    return;
                }

                if (draft.baseVersion > this.documentVersion) {
                    window.offlineDraft.clearDraft(this.docUuid);
                    return;
                }

                // Same version. Outline::apply() stamps toc.entries and
                // crossRef.label into the stored document, so those come off
                // both sides or every load would look like a difference.
                if (!window.DotDoc.documentsDiffer(parsed, editor.getJSON())) {
                    window.offlineDraft.clearDraft(this.docUuid);
                    return;
                }

                if (!confirm('An unsaved offline draft of this document was found. Restore it?')) {
                    // Declining is not the same as discarding, and this is a
                    // single confirm() with no undo behind it. Park the draft
                    // rather than delete it, so a mis-click stays recoverable
                    // for the 7 days the sweep leaves it alone.
                    await window.offlineDraft.parkStaleDraft(this.docUuid, draft.json, draft.baseVersion);
                    window.offlineDraft.clearDraft(this.docUuid);
                    console.info(
                        '[Dot.Doc] The offline draft was not restored. It was kept as stale-' +
                        this.docUuid + ' and will be removed after 7 days.'
                    );
                    return;
                }

                try {
                    editor.commands.setContent(parsed, { errorOnInvalidContent: true });
                } catch (_) {
                    // Unopenable: keep the draft rather than lose it.
                    console.info('[Dot.Doc] The offline draft could not be applied and has been kept.');
                    return;
                }
                window.offlineDraft.clearDraft(this.docUuid);
            } catch (_) {}
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
                .listen('.document.updated', (e) => {
                    // Only apply remote updates if from another user.
                    if (e.editor?.id === {{ auth()->id() }}) return;
                    const handle = window.DotDoc?.get(this.$refs.editorEl);
                    // applyRemote (not setContent) cancels the pending
                    // autosave that would otherwise send the pre-merge
                    // document straight back, keeps the change out of the
                    // local undo stack, and refuses JSON this editor cannot
                    // parse instead of blanking the page.
                    if (!handle || handle.autosaves === false || !handle.applyRemote(e.json)) return;
                    if (Number.isFinite(e.version)) this.baseVersion = e.version;
                    // The server now holds newer content than any draft, and
                    // what the draft protected has just been superseded.
                    if (window.offlineDraft) window.offlineDraft.clearDraft(this.docUuid);
                    this.tick++;
                    this.refreshOutline();
                })
                .listen('.user.joined', (e) => {
                    @this.heartbeat();
                })
                .listen('.user.left', (e) => {
                    @this.heartbeat();
                })
                .listen('.comment.posted', (e) => {
                    Livewire.dispatch('comment-posted', e);
                });
        },

        destroy() {
            clearTimeout(this.heartbeatTimer);
            clearTimeout(this.typingTimeout);
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

        // An accepted suggestion is a document the server has already stored,
        // so it arrives as JSON and goes in the same way a collaborator's
        // update does: validated, outside the undo stack, and refused rather
        // than blanking the page.
        applySuggestion(content) {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            if (!handle) return;
            // The same fail-closed gate the Echo listener has. In that mode
            // the content check refused the document: the editor is read-only
            // and what it is showing is not the document, so merging an
            // accepted suggestion into the view would show the writer a
            // document that exists nowhere.
            if (handle.autosaves === false) {
                this.aiError = 'This document is open read-only, so the accepted suggestion was not applied here. Reload the page once the content problem is fixed.';
                return;
            }
            if (!handle.applyRemote(content)) {
                this.aiError = 'That suggestion could not be applied — the document is unchanged.';
                return;
            }
            this.aiError = '';
            this.tick++;
            this.refreshOutline();
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
    @suggestion-accepted.window="applySuggestion($event.detail.content)"
    @voice-transcript.window="insertVoiceText($event.detail.text)"
    @style-changed.window="document.getElementById('doc-style').textContent = $event.detail.css; refreshOutline()"
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
                @click="thumbnailsOpen = !thumbnailsOpen">Pages</button>

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

            <span class="status-word status-word-danger" x-show="aiError" x-cloak
                  @click="aiError = ''" style="cursor:pointer" title="Click to dismiss">
                <span class="status-word-dot" aria-hidden="true"></span>
                <span x-text="aiError"></span>
            </span>

            @error('content')
                <x-shell.status-word tone="danger" :title="$message"
                                     :word="'Not saved — '.\Illuminate\Support\Str::limit($message, 60)" />
            @else
                <x-shell.status-word tone="good" :word="$saved ? 'Saved' : 'Ready'"
                                     wire:loading.remove wire:target="saveContent,saveTitle"
                                     x-show="!isTyping && !isOffline" />
            @enderror

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

        <div class="editor-thumbnails" x-show="thumbnailsOpen" x-cloak
             aria-label="Page thumbnails">
            <div class="dotdoc-thumbnail-rail" wire:ignore
                 x-init="$nextTick(() => window.DotDoc.pagination.setMode(window.DotDoc.pagination.mode))"></div>
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
