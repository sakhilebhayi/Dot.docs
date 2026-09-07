<div class="py-8 max-w-2xl mx-auto px-4">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('documents.edit', $document->uuid) }}" class="text-gray-400 hover:text-gray-600 text-sm">← Back to editor</a>
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Document Settings</h1>
    </div>

    @if(session('status'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- General Settings --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">General</h2>

        <form wire:submit="save">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                <input wire:model="title" type="text"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('title') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3 mb-4">
                <input wire:model="isPublic" type="checkbox" id="is_public"
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                <label for="is_public" class="text-sm text-gray-700 dark:text-gray-300">
                    Make document publicly accessible (shareable link)
                </label>
            </div>

            <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                Save Settings
            </button>
        </form>
    </div>

    {{-- Organization --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Organization</h2>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Folder</label>
            <div class="flex gap-2">
                <select wire:model="folderId"
                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">No folder (root)</option>
                    @foreach($this->availableFolders as $folder)
                        <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                    @endforeach
                </select>
                <button wire:click="moveToFolder" type="button"
                        class="px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition">
                    Move
                </button>
            </div>
            @error('folderId') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tags</label>
            <div class="flex flex-wrap gap-2 mb-2">
                @forelse($this->tags as $tag)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 text-xs rounded-full">
                        🏷️ {{ $tag->name }}
                        <button wire:click="removeTag({{ $tag->id }})" class="text-indigo-400 hover:text-red-500">✕</button>
                    </span>
                @empty
                    <span class="text-xs text-gray-400">No tags yet.</span>
                @endforelse
            </div>
            <form wire:submit="addTag" class="flex gap-2">
                <input wire:model="newTagName" type="text" placeholder="Add a tag…"
                       class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                <button type="submit"
                        class="px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition">
                    Add
                </button>
            </form>
        </div>
    </div>

    {{-- Page setup --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Page setup</h2>

        <form wire:submit="savePageSetup">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Paper size</label>
                    <select wire:model="pageSize"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="A4">A4</option>
                        <option value="A3">A3</option>
                        <option value="Letter">Letter</option>
                    </select>
                    @error('pageSize') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Orientation</label>
                    <select wire:model="orientation"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="portrait">Portrait</option>
                        <option value="landscape">Landscape</option>
                    </select>
                    @error('orientation') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Margin top</label>
                    <input wire:model="marginTop" type="text" placeholder="25mm"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('marginTop') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Margin right</label>
                    <input wire:model="marginRight" type="text" placeholder="20mm"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('marginRight') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Margin bottom</label>
                    <input wire:model="marginBottom" type="text" placeholder="25mm"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('marginBottom') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Margin left</label>
                    <input wire:model="marginLeft" type="text" placeholder="20mm"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                    @error('marginLeft') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Header template</label>
                <input wire:model="header" type="text" placeholder="@{{ title }}"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('header') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Footer template</label>
                <input wire:model="footer" type="text" placeholder="Page @{{ page }} of @{{ pages }}"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('footer') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 mt-1">Supports @{{ title }}, @{{ date }}, @{{ team }}, @{{ page }}, @{{ pages }} and any document variable.</p>
            </div>

            <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                Save Page Setup
            </button>
        </form>
    </div>

    {{-- Transfer Ownership --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Transfer Ownership</h2>

        <form wire:submit="transferOwnership">
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New Owner Email</label>
                <input wire:model="transferEmail" type="email" placeholder="user@example.com"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('transferEmail') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                    class="px-4 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 transition">
                Transfer
            </button>
        </form>
    </div>

    {{-- Webhooks --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
        @livewire('documents.webhook-manager', ['document' => $document], key('webhook-manager'))
    </div>

    {{-- Danger Zone --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-red-200 dark:border-red-900 p-6">
        <h2 class="text-base font-semibold text-red-600 dark:text-red-400 mb-4">Danger Zone</h2>

        @if(!$showDeleteConfirm)
            <button wire:click="$set('showDeleteConfirm', true)"
                    class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition">
                Delete Document
            </button>
        @else
            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">Are you sure? This cannot be undone.</p>
            <div class="flex gap-3">
                <button wire:click="delete"
                        class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition">
                    Yes, Delete
                </button>
                <button wire:click="$set('showDeleteConfirm', false)"
                        class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900 transition">
                    Cancel
                </button>
            </div>
        @endif
    </div>
</div>
