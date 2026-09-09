<div
    x-data="{
        owns: false,
        echo: null,
        heartbeatInterval: null,
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

            // Heartbeat every 60 seconds to keep presence alive
            this.heartbeatInterval = setInterval(() => {
                @this.heartbeat();
            }, 60000);

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
            clearInterval(this.heartbeatInterval);
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
    class="flex flex-col h-screen bg-gray-50 dark:bg-gray-900"
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

    {{-- Toolbar --}}
    <div class="sticky top-0 z-10 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-2 flex items-center gap-1 flex-wrap">
        {{-- Title in toolbar --}}
        <div class="flex-1 min-w-0 mr-4">
            <input wire:model.blur="title"
                   wire:change="saveTitle"
                   type="text"
                   class="w-full text-lg font-semibold bg-transparent border-none focus:ring-0 text-gray-900 dark:text-white truncate p-0"
                   placeholder="Untitled" />
        </div>

        {{-- Style switcher (Task 9 restyles this) --}}
        <select wire:change="setStyle($event.target.value)"
                class="text-xs border border-gray-300 rounded px-1 py-1 bg-white dark:bg-gray-700 dark:border-gray-600 dark:text-white mr-2"
                title="Document style">
            @foreach(\App\Styles\StyleEngine::systemKeys() as $styleKey)
                <option value="{{ $styleKey }}" @selected($document->style_key === $styleKey)>{{ ucfirst($styleKey) }}</option>
            @endforeach
        </select>
        @error('style')
            <span class="text-xs text-red-500 mr-2">{{ $message }}</span>
        @enderror

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Format buttons --}}
        <button @click="ed().chain().focus().toggleBold().run()" title="Bold"
                :class="ed()?.isActive('bold') ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm font-bold">B</button>

        <button @click="ed().chain().focus().toggleItalic().run()" title="Italic"
                :class="ed()?.isActive('italic') ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm italic">I</button>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        @foreach([1,2,3] as $h)
            <button @click="window.DotDoc.run(ed(), 'heading.{{ $h }}')"
                    :class="ed()?.isActive('heading', { level: {{ $h }} }) ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                    class="p-1.5 rounded transition text-xs font-bold">H{{ $h }}</button>
        @endforeach

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        <button @click="window.DotDoc.run(ed(), 'list.bullet')"
                :class="ed()?.isActive('bulletList') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm">• List</button>

        <button @click="window.DotDoc.run(ed(), 'list.ordered')"
                :class="ed()?.isActive('orderedList') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm">1. List</button>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        <button @click="window.DotDoc.run(ed(), 'quote')"
                :class="ed()?.isActive('blockquote') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm" title="Blockquote">"</button>

        <button @click="ed().chain().focus().toggleCode().run()"
                :class="ed()?.isActive('code') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-xs font-mono" title="Inline code">&lt;/&gt;</button>

        {{-- Through the registry, never editor.chain() directly: the registry
             wraps every block insert in the caption guard, so this cannot
             split a figure away from its media. --}}
        <button @click="window.DotDoc.run(ed(), 'table')"
                class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" title="Insert Table">⊞ Table</button>

        {{-- Image upload --}}
        <label class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 cursor-pointer" title="Insert Image">
            🖼
            {{-- uploadImage() re-checks the caption guard at the moment it
                 inserts, because the file dialog is asynchronous. --}}
            <input type="file" accept="image/*" class="hidden"
                   @change="ed().uploadImage($event.target.files[0]); $event.target.value = ''" />
        </label>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Everything structural (TOC, figure, cross-reference, callout,
             columns, breaks, variables) lives in the command registry, which
             the palette and the slash menu both list. --}}
        <button @click="window.DotDoc.openPalette(ed())"
                title="Commands (⌘K) — or type / in the document"
                class="p-1.5 rounded transition text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 flex items-center gap-1">
            ⌘K <span class="hidden sm:inline">Commands</span>
        </button>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        <button @click="ed().chain().focus().undo().run()" title="Undo"
                class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">↩</button>
        <button @click="ed().chain().focus().redo().run()" title="Redo"
                class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">↪</button>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Voice Typing --}}
        <div x-data="voiceTyping" x-init="init()" class="relative">
            <button @click="toggle()"
                    :title="listening ? 'Stop voice typing' : 'Start voice typing (Web Speech API)'"
                    :class="listening ? 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400 animate-pulse' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                    class="p-1.5 rounded transition text-sm flex items-center gap-1"
                    x-show="supported">
                🎤 <span class="text-xs hidden sm:inline" x-text="listening ? 'Listening…' : 'Voice'"></span>
            </button>
            <span x-show="!supported" class="hidden" title="Speech recognition not supported in this browser"></span>
        </div>

        {{-- Right side: presence + status + nav --}}
        <div class="ml-auto flex items-center gap-3">
            {{-- Active user avatars --}}
            @if(count($activeUsers) > 0)
                <div class="flex items-center -space-x-1.5">
                    @foreach(array_slice($activeUsers, 0, 4) as $member)
                        <div title="{{ $member['name'] }}"
                             class="w-7 h-7 rounded-full border-2 border-white dark:border-gray-800 overflow-hidden bg-indigo-500 flex items-center justify-center text-white text-xs font-bold">
                            @if(!empty($member['avatar']))
                                <img src="{{ $member['avatar'] }}" alt="{{ $member['name'] }}" class="w-full h-full object-cover" />
                            @else
                                {{ strtoupper(substr($member['name'], 0, 1)) }}
                            @endif
                        </div>
                    @endforeach
                    @if(count($activeUsers) > 4)
                        <div class="w-7 h-7 rounded-full border-2 border-white dark:border-gray-800 bg-gray-400 flex items-center justify-center text-white text-xs font-bold">
                            +{{ count($activeUsers) - 4 }}
                        </div>
                    @endif
                </div>
            @endif

            {{-- Typing / save indicator --}}
            <div class="flex items-center gap-1 text-xs text-gray-400">
                <span x-show="isOffline"
                      class="flex items-center gap-1 px-2 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded font-medium"
                      title="You are offline. Edits are saved locally and will sync when back online.">
                    ⚠ Offline
                </span>
                <span x-show="isTyping && !isOffline" class="flex items-center gap-1">
                    <span class="w-1.5 h-1.5 bg-amber-400 rounded-full animate-pulse"></span>
                    editing
                </span>
                <span wire:loading wire:target="saveContent,saveTitle" class="animate-pulse">Saving…</span>
                {{-- A rejected save (DocumentSchema validation) must be
                     visible: the editor keeps typing over content the server
                     never accepted, and the offline draft is deliberately
                     kept as the only remaining copy. --}}
                <span x-show="aiError" x-cloak x-text="aiError" @click="aiError = ''"
                      class="flex items-center gap-1 px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded font-medium cursor-pointer"
                      title="Click to dismiss"></span>
                @error('content')
                    <span class="flex items-center gap-1 px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded font-medium"
                          title="{{ $message }}">
                        ⚠ Not saved — {{ \Illuminate\Support\Str::limit($message, 60) }}
                    </span>
                @else
                    <span wire:loading.remove wire:target="saveContent,saveTitle" x-show="!isTyping && !isOffline" class="text-green-500">
                        @if($saved) ✓ Saved @endif
                    </span>
                @enderror
            </div>

            {{-- Last edited by --}}
            <span class="text-xs text-gray-400 hidden lg:block">
                v{{ $document->version }}
                · edited {{ $document->updated_at->diffForHumans() }}
            </span>

            {{-- AI Quick Actions --}}
            <span class="w-px h-5 bg-gray-300 dark:bg-gray-600"></span>
            <button @click="$dispatch('open-ai-palette')"
                    class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium flex items-center gap-1 px-1.5 py-1 rounded hover:bg-indigo-50 dark:hover:bg-indigo-900/30"
                    title="AI Command Palette (Ctrl+Shift+K)">
                ✨ AI <kbd class="text-[9px] bg-gray-100 dark:bg-gray-700 rounded px-1 ml-0.5">⇧⌘K</kbd>
            </button>
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open"
                        class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 px-1 rounded hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                    ▾
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak
                     class="absolute right-0 mt-1 w-44 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded shadow-lg z-50 py-1 text-xs">
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'summarize' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        📝 Summarize
                    </button>
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'grammar' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        ✅ Fix Grammar
                    </button>
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'continue' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        ✍️ Continue Writing
                    </button>
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'outline' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        🗂️ Generate Outline
                    </button>
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'tone', param: 'formal' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        🎩 Formal Tone
                    </button>
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'tone', param: 'casual' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        😊 Casual Tone
                    </button>
                    <button @click="open=false; $wire.dispatchTo('documents.ai-assistant', 'ai-action', { action: 'tone', param: 'concise' })"
                            class="w-full text-left px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        ⚡ Concise Tone
                    </button>
                </div>
            </div>

            <a href="{{ route('documents.share', $document->uuid) }}"
               class="text-xs text-indigo-600 hover:underline">Share</a>

            {{-- Suggestion mode toggle --}}
            <span class="w-px h-5 bg-gray-300 dark:bg-gray-600"></span>
            <button wire:click="toggleSuggestionMode"
                    class="text-xs px-2 py-1 rounded transition {{ $suggestionMode ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 font-semibold' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700' }}"
                    title="{{ $suggestionMode ? 'Exit suggesting mode (track changes)' : 'Enter suggesting mode (track changes)' }}">
                ✏️ {{ $suggestionMode ? 'Suggesting' : 'Editing' }}
            </button>

            {{-- Comments sidebar toggle --}}
            <button wire:click="toggleCommentSidebar"
                    class="text-xs px-2 py-1 rounded transition {{ $commentSidebarOpen ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400 font-semibold' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700' }}"
                    title="{{ $commentSidebarOpen ? 'Close comments' : 'Open comments' }}">
                💬 Comments
            </button>
            <a href="{{ route('documents.history', $document->uuid) }}"
               class="text-xs text-gray-500 hover:underline">History</a>

            {{-- Export dropdown --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open"
                        class="text-xs text-gray-500 hover:underline flex items-center gap-0.5">
                    Export ▾
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak
                     class="absolute right-0 mt-1 w-36 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded shadow-lg z-50 py-1 text-xs">
                    <a href="{{ route('documents.export', [$document->uuid, 'pdf']) }}"
                       class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">PDF</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'word']) }}"
                       class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">Word (.docx)</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'html']) }}"
                       class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">HTML</a>
                    <a href="{{ route('documents.export', [$document->uuid, 'markdown']) }}"
                       class="block px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">Markdown</a>
                </div>
            </div>

            {{-- Import --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open"
                        class="text-xs text-gray-500 hover:underline flex items-center gap-0.5">
                    Import ▾
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak
                     class="absolute right-0 mt-1 w-52 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded shadow-lg z-50 p-3 text-xs">
                    <form action="{{ route('documents.import', $document->uuid) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <label class="block text-gray-600 dark:text-gray-400 mb-1">Upload .docx or .md file</label>
                        <input type="file" name="file" accept=".docx,.md,.markdown,.txt"
                               class="block w-full text-xs border border-gray-300 rounded p-1 mb-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                        <button type="submit"
                                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded px-2 py-1 text-xs">
                            Import
                        </button>
                    </form>
                </div>
            </div>

            <a href="{{ route('documents.settings', $document->uuid) }}"
               class="text-xs text-gray-500 hover:underline">Settings</a>
            <button @click="$dispatch('open-save-as-template')"
                    class="text-xs text-gray-500 hover:underline hidden sm:block"
                    title="Save this document as a reusable template">
                📄 Template
            </button>
            <a href="{{ route('documents.index') }}"
               class="text-xs text-gray-500 hover:underline">← All Docs</a>
        </div>
    </div>

    {{-- Pending suggestions panel (track changes) --}}
    @if(count($pendingSuggestions) > 0)
        <div class="bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 px-4 py-2">
            <div class="max-w-4xl mx-auto">
                <p class="text-xs font-semibold text-amber-700 dark:text-amber-400 mb-1.5">
                    {{ count($pendingSuggestions) }} pending suggestion{{ count($pendingSuggestions) !== 1 ? 's' : '' }}
                </p>
                <div class="flex flex-col gap-1.5">
                    @foreach($pendingSuggestions as $suggestion)
                        <div class="flex items-center gap-2 text-xs bg-white dark:bg-gray-800 rounded border border-amber-200 dark:border-amber-700 px-3 py-1.5">
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $suggestion['user'] }}</span>
                            <span class="text-gray-400">·</span>
                            <span class="text-gray-400">{{ $suggestion['created_at'] }}</span>
                            <span class="text-gray-400">·</span>
                            <span class="flex-1 truncate text-gray-600 dark:text-gray-400 italic">{{ $suggestion['excerpt'] }}</span>
                            <button wire:click="acceptSuggestion({{ $suggestion['id'] }})"
                                    class="flex-shrink-0 px-2 py-0.5 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded hover:bg-green-200 dark:hover:bg-green-900/50 transition font-medium">
                                Accept
                            </button>
                            <button wire:click="rejectSuggestion({{ $suggestion['id'] }})"
                                    class="flex-shrink-0 px-2 py-0.5 bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded hover:bg-red-200 dark:hover:bg-red-900/50 transition font-medium">
                                Reject
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Editor area (with optional comment sidebar) --}}
    <div class="flex flex-1 overflow-hidden">
        {{-- Main editor. wire:ignore keeps Livewire's DOM morph out of the
             ProseMirror subtree, which it did not render and must not diff. --}}
        <div class="flex-1 overflow-auto">
            {{-- data-outline seeds the numbering before the first save round
                 trip; it rides on the wire:ignore'd host so a Livewire morph
                 never rewrites it. --}}
            <div x-ref="editorEl" wire:ignore class="desk" data-outline="{{ json_encode($outline) }}"></div>
        </div>

        {{-- Comment sidebar --}}
        @if($commentSidebarOpen)
            <div class="w-80 flex-shrink-0 border-l border-gray-200 dark:border-gray-700 overflow-y-auto bg-white dark:bg-gray-800">
                @livewire('documents.comment-thread', ['document' => $document], key('comment-thread'))
            </div>
        @endif
    </div>
</div>
