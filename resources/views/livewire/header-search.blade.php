<div class="shell-search">
    <x-dot.search wire="q" name="q" :action="route('documents.index')" label="Search documents"
                  placeholder="Search documents" :hint="$editor ? null : 'Ctrl K'" :hint-key="! $editor"
                  results-nav escape-clears loading-text="Looking in documents..."
                  :root-attributes="['wire:ignore.self' => '', 'wire:loading.attr' => 'data-dsr-loading', 'wire:target' => 'q']">
        <x-slot:results>
            @if ($searching)
                @php($rows = $this->results)
                @forelse ($rows as $document)
                    <x-dot.search-row :href="route('documents.edit', $document->uuid)" :label="$document->title ?: 'Untitled'"
                                      :tag="$document->is_public ? 'Public' : null" wire:key="hs-{{ $document->uuid }}" />
                @empty
                    <p class="dsr-foot">No documents{{ $company ? ' in '.$company : '' }} match "{{ $q }}".</p>
                @endforelse
            @endif
        </x-slot:results>
    </x-dot.search>
</div>
