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
                clearTimeout(this.typingTimeout);
                this.typingTimeout = setTimeout(() => { this.isTyping = false; }, 1000);
                // Never write a draft in fail-closed mode: what the editor is
                // holding then is not the document.
                if (window.offlineDraft && handle.autosaves !== false) {
                    window.offlineDraft.saveDraft(this.docUuid, JSON.stringify(editor.getJSON()), this.baseVersion);
                }
            });

            this.refreshOutline();
            this.restoreDraftIfRestorable();
            this.setupEcho();

            // Online / offline events (dispatched by offline.js initOfflineSupport)
            window.addEventListener('app-offline', () => { this.isOffline = true; });
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
                if (outline) window.DotDoc.setOutline(outline);
            });
        },

        // Registry commands in the 'system' group need the page to act.
        hostCommand(name, params) {
            if (name === 'export.pdf') {
                window.location.href = '{{ route('documents.export', [$document->uuid, 'pdf']) }}';
            } else if (name === 'style.switch' && params && params.key) {
                @this.setStyle(params.key);
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
    <livewire:documents.ai-assistant :document="$document" wire:key="ai-assistant" lazy />
    <livewire:documents.ai-chat :document="$document" wire:key="ai-chat" lazy />
    <livewire:documents.save-as-template :document="$document" wire:key="save-as-template" lazy />

    {{-- Listen for Ctrl+K to open AI palette --}}
    <div x-data
         @open-ai-palette.window="Livewire.dispatchTo('documents.ai-assistant', 'open-palette')"
         @open-save-as-template.window="Livewire.dispatchTo('documents.save-as-template', 'open')"
         class="hidden"></div>
    {{-- ── Toolbar ──────────────────────────────────────────────────────
         Every structural insert goes through window.DotDoc.run(), never
         editor.chain(): the registry wraps each one in the caption guard, so a
         toolbar button cannot split a figure from its media.
         See .ai/rules/editor.md. --}}
    <div class="doc-toolbar">
        <label class="sr-only" for="doc-title">Document title</label>
        <input id="doc-title"
               wire:model.blur="title"
               wire:change="saveTitle"
               type="text"
               class="doc-title-field"
               placeholder="Untitled" />

        <span class="tool-sep" aria-hidden="true"></span>

        <label class="sr-only" for="doc-style-picker">Document style</label>
        <select id="doc-style-picker" wire:change="setStyle($event.target.value)" class="field field-mono"
                style="width:auto;padding:5px var(--s2)">
            @foreach (\App\Styles\StyleEngine::systemKeys() as $styleKey)
                <option value="{{ $styleKey }}" @selected($document->style_key === $styleKey)>{{ ucfirst($styleKey) }}</option>
            @endforeach
        </select>
        @error('style')
            <span class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</span>
        @enderror

        <span class="tool-sep" aria-hidden="true"></span>

        <button type="button" class="tool" style="font-weight:700" title="Bold" aria-label="Bold"
                :class="ed()?.isActive('bold') ? 'tool is-on' : 'tool'"
                @click="ed().chain().focus().toggleBold().run()">B</button>

        <button type="button" class="tool" style="font-style:italic" title="Italic" aria-label="Italic"
                :class="ed()?.isActive('italic') ? 'tool is-on' : 'tool'"
                @click="ed().chain().focus().toggleItalic().run()">I</button>

        <span class="tool-sep" aria-hidden="true"></span>

        @foreach ([1, 2, 3] as $h)
            <button type="button" class="tool tool-mono" title="Heading {{ $h }}" aria-label="Heading {{ $h }}"
                    :class="ed()?.isActive('heading', { level: {{ $h }} }) ? 'tool tool-mono is-on' : 'tool tool-mono'"
                    @click="window.DotDoc.run(ed(), 'heading.{{ $h }}')">H{{ $h }}</button>
        @endforeach

        <span class="tool-sep" aria-hidden="true"></span>

        <button type="button" class="tool tool-mono" title="Bulleted list" aria-label="Bulleted list"
                :class="ed()?.isActive('bulletList') ? 'tool tool-mono is-on' : 'tool tool-mono'"
                @click="window.DotDoc.run(ed(), 'list.bullet')">List</button>

        <button type="button" class="tool tool-mono" title="Numbered list" aria-label="Numbered list"
                :class="ed()?.isActive('orderedList') ? 'tool tool-mono is-on' : 'tool tool-mono'"
                @click="window.DotDoc.run(ed(), 'list.ordered')">1.</button>

        <button type="button" class="tool tool-mono" title="Blockquote" aria-label="Blockquote"
                :class="ed()?.isActive('blockquote') ? 'tool tool-mono is-on' : 'tool tool-mono'"
                @click="window.DotDoc.run(ed(), 'quote')">Quote</button>

        <button type="button" class="tool tool-mono" title="Inline code" aria-label="Inline code"
                :class="ed()?.isActive('code') ? 'tool tool-mono is-on' : 'tool tool-mono'"
                @click="ed().chain().focus().toggleCode().run()">Code</button>

        <button type="button" class="tool tool-mono" title="Insert a table" aria-label="Insert a table"
                @click="window.DotDoc.run(ed(), 'table')">Table</button>

        {{-- uploadImage() re-checks the caption guard at the moment it inserts,
             because the file dialog is asynchronous. --}}
        <label class="tool tool-mono" style="display:inline-flex;align-items:center" title="Insert an image">
            Image
            <input type="file" accept="image/*" class="sr-only"
                   @change="ed().uploadImage($event.target.files[0]); $event.target.value = ''" />
        </label>

        <span class="tool-sep" aria-hidden="true"></span>

        {{-- Everything structural (TOC, figure, cross-reference, callout,
             columns, breaks, variables) lives in the command registry, which
             the palette and the slash menu both list. --}}
        <button type="button" class="tool tool-mono" title="Commands — or type / in the document"
                @click="window.DotDoc.openPalette(ed())">&#8984;K Commands</button>

        <button type="button" class="tool tool-mono" title="Undo" aria-label="Undo"
                @click="ed().chain().focus().undo().run()">Undo</button>
        <button type="button" class="tool tool-mono" title="Redo" aria-label="Redo"
                @click="ed().chain().focus().redo().run()">Redo</button>

        <div x-data="voiceTyping" x-init="init()">
            <button type="button" class="tool tool-mono" x-show="supported" @click="toggle()"
                    :class="listening ? 'tool tool-mono is-on' : 'tool tool-mono'"
                    :title="listening ? 'Stop voice typing' : 'Start voice typing'"
                    x-text="listening ? 'Listening' : 'Voice'">Voice</button>
        </div>

        {{-- ── Right side: presence, save state, actions ───────────────── --}}
        <div class="doc-status">
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

            {{-- State is a lamp AND a word, never colour alone. A rejected save
                 has to be visible: the writer keeps typing over content the
                 server never accepted, and the offline draft is deliberately
                 kept as the only remaining copy. --}}
            <span class="status-item" style="border-right:0" aria-live="polite">
                <span x-show="isOffline" class="toolbar" style="gap:var(--s2)"
                      title="Edits are saved in this browser and sync when you are back online.">
                    <span class="lamp lamp-signal" aria-hidden="true"></span>
                    <span class="readout">Offline</span>
                </span>
                <span x-show="isTyping && !isOffline" class="toolbar" style="gap:var(--s2)">
                    <span class="lamp lamp-marker" aria-hidden="true"></span>
                    <span class="readout">Editing</span>
                </span>
                <span wire:loading wire:target="saveContent,saveTitle" class="toolbar" style="gap:var(--s2)">
                    <span class="lamp lamp-signal" aria-hidden="true"></span>
                    <span class="readout">Saving</span>
                </span>
                <span x-show="aiError" x-cloak @click="aiError = ''" class="toolbar" style="gap:var(--s2);cursor:pointer"
                      title="Click to dismiss">
                    <span class="lamp lamp-danger" aria-hidden="true"></span>
                    <span class="readout" x-text="aiError"></span>
                </span>
                @error('content')
                    <span class="toolbar" style="gap:var(--s2)" title="{{ $message }}">
                        <span class="lamp lamp-danger" aria-hidden="true"></span>
                        <span class="readout">Not saved — {{ \Illuminate\Support\Str::limit($message, 60) }}</span>
                    </span>
                @else
                    <span wire:loading.remove wire:target="saveContent,saveTitle" x-show="!isTyping && !isOffline"
                          class="toolbar" style="gap:var(--s2)">
                        <span class="lamp lamp-good" aria-hidden="true"></span>
                        <span class="readout">@if ($saved) Saved @else Ready @endif</span>
                    </span>
                @enderror
            </span>

            <span class="readout" title="Last edited {{ $document->updated_at->diffForHumans() }}">
                <x-shell.figure :value="$document->version" :width="4" prefix="v" label="Version" />
            </span>

            <span class="tool-sep" aria-hidden="true"></span>

            <button type="button" class="tool tool-mono" @click="$dispatch('open-ai-palette')"
                    title="Ask the assistant (Ctrl+Shift+K)">Assistant</button>

            <button type="button" class="tool tool-mono {{ $suggestionMode ? 'is-on' : '' }}"
                    wire:click="toggleSuggestionMode"
                    title="{{ $suggestionMode ? 'Leave suggesting mode' : 'Enter suggesting mode (track changes)' }}">
                {{ $suggestionMode ? 'Suggesting' : 'Editing' }}
            </button>

            <button type="button" class="tool tool-mono {{ $commentSidebarOpen ? 'is-on' : '' }}"
                    wire:click="toggleCommentSidebar">Comments</button>

            <div class="menu" x-data="{ open: false }">
                <button type="button" class="tool tool-mono" @click="open = !open" :aria-expanded="open ? 'true' : 'false'">Export</button>
                <div class="menu-list" x-show="open" @click.outside="open = false" x-cloak>
                    <a href="{{ route('documents.export', [$document->uuid, 'pdf']) }}">PDF</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'word']) }}">Word (.docx)</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'html']) }}">HTML</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'markdown']) }}">Markdown</a>
                </div>
            </div>

            <div class="menu" x-data="{ open: false }">
                <button type="button" class="tool tool-mono" @click="open = !open" :aria-expanded="open ? 'true' : 'false'">Import</button>
                <div class="menu-list" x-show="open" @click.outside="open = false" x-cloak style="padding:var(--s3);min-width:260px">
                    <form action="{{ route('documents.import', $document->uuid) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <label class="field-label" for="doc-import">Upload a .docx or .md file</label>
                        <input id="doc-import" type="file" name="file" accept=".docx,.md,.markdown,.txt" class="field" />
                        <button type="submit" class="btn btn-primary" style="margin-top:var(--s3);width:100%">Import it</button>
                    </form>
                </div>
            </div>

            <button type="button" class="tool tool-mono" @click="$dispatch('open-save-as-template')"
                    title="Save this document as a reusable template">Template</button>
        </div>
    </div>

    {{-- Pending suggestions: the assistant's ink, still in marker. --}}
    @if (count($pendingSuggestions) > 0)
        <section class="panel" aria-labelledby="pending-suggestions" style="border-left:0;border-right:0;border-top:0">
            <div class="panel-head">
                <h2 class="section-title" id="pending-suggestions">
                    Waiting for you — <x-shell.figure :value="count($pendingSuggestions)" :width="2" label="Suggestions waiting" />
                </h2>
                <span class="readout">Marker becomes graphite once accepted</span>
            </div>
            <ul class="ledger">
                @foreach ($pendingSuggestions as $suggestion)
                    <li class="ledger-row">
                        <span class="lamp lamp-marker" aria-hidden="true"></span>
                        <span class="ledger-key">
                            {{ $suggestion['excerpt'] }}
                            <span class="ledger-sub">{{ $suggestion['user'] }} · {{ $suggestion['created_at'] }}</span>
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
             seeds the numbering before the first save round trip. --}}
        <div class="editor-main">
            <div x-ref="editorEl" wire:ignore class="desk" data-outline="{{ json_encode($outline) }}"></div>
        </div>

        @if ($commentSidebarOpen)
            <div class="editor-side">
                @livewire('documents.comment-thread', ['document' => $document], key('comment-thread'))
            </div>
        @endif
    </div>
</div>
