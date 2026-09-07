<style id="doc-style">{!! $styleCss !!}</style>

<div
    x-data="{
        editor: null,
        echo: null,
        saveTimeout: null,
        heartbeatInterval: null,
        isTyping: false,
        typingTimeout: null,
        isOffline: !navigator.onLine,
        docUuid: '{{ $document->uuid }}',
        remoteVersion: @entangle('document.version').live,

        init() {
            this.editor = window.createTipTapEditor({
                element: this.$refs.editorEl,
                content: @js($contentJson),
                uploadUrl: '{{ route('documents.images.store', $document->uuid) }}',
                csrfToken: document.querySelector('meta[name=csrf-token]').content,
                onChange: (html) => {
                    // Show typing indicator
                    this.isTyping = true;
                    clearTimeout(this.typingTimeout);
                    this.typingTimeout = setTimeout(() => { this.isTyping = false; }, 1000);

                    // Always persist draft to IndexedDB (works offline too)
                    if (window.offlineDraft) {
                        window.offlineDraft.saveDraft(this.docUuid, html);
                    }

                    // Debounced autosave (skipped when offline — SW queues it)
                    clearTimeout(this.saveTimeout);
                    this.saveTimeout = setTimeout(() => {
                        @this.saveContent(this.editor.getJSON());
                    }, 1500);
                }
            });

            // Restore IndexedDB draft if newer than server content
            this.restoreDraftIfNewer();

            this.setupEcho();

            // Online / offline events (dispatched by offline.js initOfflineSupport)
            window.addEventListener('app-offline', () => { this.isOffline = true; });
            window.addEventListener('app-online',  () => {
                this.isOffline = false;
                // Flush current draft to server now that we're back online
                if (this.editor) @this.saveContent(this.editor.getJSON());
                if (window.offlineDraft) window.offlineDraft.clearDraft(this.docUuid);
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

        async restoreDraftIfNewer() {
            if (!window.offlineDraft) return;
            try {
                const draft = await window.offlineDraft.loadDraft(this.docUuid);
                if (draft && draft !== this.editor?.getHTML()) {
                    // Only prompt if draft appears to differ from current server content
                    const serverLen = (this.editor?.getText() || '').length;
                    const draftLen  = draft.replace(/<[^>]+>/g, '').length;
                    if (draftLen > serverLen) {
                        if (confirm('An unsaved offline draft was found. Restore it?')) {
                            this.editor.commands.setContent(draft, false);
                        }
                        window.offlineDraft.clearDraft(this.docUuid);
                    }
                }
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
                    // Only apply remote updates if from another user
                    if (e.editor.id !== {{ auth()->id() }}) {
                        const currentPos = this.editor.state.selection.anchor;
                        this.editor.commands.setContent(e.json ?? e.content, false);
                        // Try to restore cursor position
                        try { this.editor.commands.setTextSelection(currentPos); } catch(_) {}
                    }
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
            if (this.echo) this.echo.leave();
            if (this.editor) this.editor.destroy();
        },

        // Apply AI result to the editor
        applyAiContent(type, content) {
            if (!this.editor) return;
            if (type === 'replace') {
                this.editor.commands.setContent(content, true);
                @this.saveContent(this.editor.getJSON());
            } else {
                this.editor.commands.focus('end');
                this.editor.commands.insertContent(content);
                @this.saveContent(this.editor.getJSON());
            }
        },

        // Insert voice-transcribed text at current cursor position
        insertVoiceText(text) {
            if (!this.editor || !text) return;
            this.editor.commands.focus();
            this.editor.commands.insertContent(text + ' ');
            clearTimeout(this.saveTimeout);
            this.saveTimeout = setTimeout(() => { @this.saveContent(this.editor.getJSON()); }, 1500);
        }
    }"
    x-init="init()"
    x-destroy="destroy()"
    @ai-apply.window="applyAiContent('replace', $event.detail.content)"
    @suggestion-accepted.window="if (editor) { editor.commands.setContent($event.detail.content, true); }"
    @voice-transcript.window="insertVoiceText($event.detail.text)"
    @keydown.ctrl.k.window.prevent="$dispatch('open-ai-palette')"
    @keydown.meta.k.window.prevent="$dispatch('open-ai-palette')"
    class="flex flex-col h-screen bg-gray-50 dark:bg-gray-900"
>
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
        <button @click="editor.chain().focus().toggleBold().run()" title="Bold"
                :class="editor?.isActive('bold') ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm font-bold">B</button>

        <button @click="editor.chain().focus().toggleItalic().run()" title="Italic"
                :class="editor?.isActive('italic') ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm italic">I</button>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        @foreach([1,2,3] as $h)
            <button @click="editor.chain().focus().toggleHeading({ level: {{ $h }} }).run()"
                    :class="editor?.isActive('heading', { level: {{ $h }} }) ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                    class="p-1.5 rounded transition text-xs font-bold">H{{ $h }}</button>
        @endforeach

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        <button @click="editor.chain().focus().toggleBulletList().run()"
                :class="editor?.isActive('bulletList') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm">• List</button>

        <button @click="editor.chain().focus().toggleOrderedList().run()"
                :class="editor?.isActive('orderedList') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm">1. List</button>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        <button @click="editor.chain().focus().toggleBlockquote().run()"
                :class="editor?.isActive('blockquote') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-sm" title="Blockquote">"</button>

        <button @click="editor.chain().focus().toggleCode().run()"
                :class="editor?.isActive('code') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                class="p-1.5 rounded transition text-xs font-mono" title="Inline code">&lt;/&gt;</button>

        <button @click="editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()"
                class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" title="Insert Table">⊞ Table</button>

        {{-- Image upload --}}
        <label class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 cursor-pointer" title="Insert Image">
            🖼
            <input type="file" accept="image/*" class="hidden"
                   @change="editor.uploadImage($event.target.files[0]); $event.target.value = ''" />
        </label>

        <span class="w-px h-5 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        <button @click="editor.chain().focus().undo().run()" title="Undo"
                class="p-1.5 rounded transition text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">↩</button>
        <button @click="editor.chain().focus().redo().run()" title="Redo"
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
                <span wire:loading.remove wire:target="saveContent,saveTitle" x-show="!isTyping && !isOffline" class="text-green-500">
                    @if($saved) ✓ Saved @endif
                </span>
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
                    title="AI Command Palette (Ctrl+K)">
                ✨ AI <kbd class="text-[9px] bg-gray-100 dark:bg-gray-700 rounded px-1 ml-0.5">⌘K</kbd>
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
        {{-- Main editor --}}
        <div class="flex-1 overflow-auto">
            <div class="{{ $commentSidebarOpen ? 'mx-auto py-10 px-6' : 'max-w-4xl mx-auto py-10 px-6' }}">
                <div x-ref="editorEl"
                     class="prose prose-lg dark:prose-invert max-w-none min-h-[60vh] focus:outline-none [&_.ProseMirror]:outline-none [&_.ProseMirror-focused]:outline-none"></div>
            </div>
        </div>

        {{-- Comment sidebar --}}
        @if($commentSidebarOpen)
            <div class="w-80 flex-shrink-0 border-l border-gray-200 dark:border-gray-700 overflow-y-auto bg-white dark:bg-gray-800">
                @livewire('documents.comment-thread', ['document' => $document], key('comment-thread'))
            </div>
        @endif
    </div>
</div>
