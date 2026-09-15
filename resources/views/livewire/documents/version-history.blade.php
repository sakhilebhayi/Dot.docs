<div class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Version history</h1>
            <p class="page-lede">Every saved state of {{ $document->title }}. Pick one to read it, or two to see what changed between them.</p>
        </div>
        <a href="{{ route('documents.edit', $document->uuid) }}" class="btn">Back to the editor</a>
    </div>

    @if (session('status'))
        <p class="note" role="status">
            <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
            {{ session('status') }}
        </p>
    @endif

    <div class="grid-2" style="gap:0;align-items:start">
        <section class="panel" aria-labelledby="version-list">
            <div class="panel-head">
                <h2 class="section-title" id="version-list">Versions</h2>
                @if (count($compareIds) > 0)
                    <span class="micro">
                        <x-shell.figure :value="count($compareIds)" />/2 selected
                    </span>
                @endif
            </div>

            @if (count($compareIds) > 0)
                <div class="panel-head">
                    <span class="toolbar">
                        <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
                        <span class="micro">Comparing</span>
                    </span>
                    <span class="toolbar">
                        @if (count($compareIds) === 2)
                            <button type="button" class="btn btn-sm btn-primary" wire:click="runDiff">Show the difference</button>
                        @endif
                        <button type="button" class="btn btn-sm" wire:click="$set('compareIds', [])">Clear</button>
                    </span>
                </div>
            @endif

            <ul class="list">
                @forelse ($versions as $version)
                    <li class="list-row">
                        <input type="checkbox"
                               class="field-box"
                               id="cmp-{{ $version->id }}"
                               wire:click="toggleCompare({{ $version->id }})"
                               @checked(in_array($version->id, $compareIds)) />
                        <label class="sr-only" for="cmp-{{ $version->id }}">Compare version {{ $version->version_number }}</label>

                        <button type="button" class="btn btn-quiet" style="flex:1 1 auto;justify-content:flex-start"
                                wire:click="preview({{ $version->id }})">
                            <span class="list-key">
                                <span class="micro">
                                    <x-shell.figure :value="$version->version_number" prefix="v" label="Version" />
                                </span>
                                <span class="list-sub">
                                    {{ $version->created_at->diffForHumans() }}@if ($version->author) · {{ $version->author->name }}@endif
                                </span>
                            </span>
                        </button>

                        @if ($version->version_number === $document->version)
                            <x-shell.status-word tone="good" word="Current" />
                        @else
                            <button type="button" class="btn btn-sm" wire:click="restore({{ $version->id }})"
                                    wire:confirm="Restore the document to v{{ $version->version_number }}? What is there now is kept as a new version.">
                                Restore
                            </button>
                        @endif
                    </li>
                @empty
                    <li class="empty">
                        <p class="empty-line">Nothing has been saved yet, so there is no history to read.</p>
                        <a href="{{ route('documents.edit', $document->uuid) }}" class="btn btn-primary">Start writing</a>
                    </li>
                @endforelse
            </ul>

            <div class="panel-foot">
                {{ $versions->links() }}
            </div>
        </section>

        <section class="panel" aria-labelledby="version-preview" style="border-left:0">
            <div class="panel-head">
                <h2 class="section-title" id="version-preview">
                    {{ $showDiff && $diffHtml ? 'Difference' : 'Preview' }}
                </h2>
                @if ($previewVersion && ! $showDiff)
                    <span class="micro">{{ $previewVersion->created_at->format('j M Y, H:i') }}</span>
                @endif
            </div>

            @if ($showDiff && $diffHtml)
                <div class="panel-body">
                    <div class="diff-wrapper">{!! $diffHtml !!}</div>
                </div>
            @elseif ($previewVersion)
                <div class="panel-body">
                    <div class="split">
                        <span class="micro">
                            <x-shell.figure :value="$previewVersion->version_number" prefix="v" label="Version" />
                            @if ($previewVersion->author) · {{ $previewVersion->author->name }} @endif
                        </span>
                        @if ($previewVersion->version_number !== $document->version)
                            <button type="button" class="btn btn-primary" wire:click="restore({{ $previewVersion->id }})"
                                    wire:confirm="Restore the document to v{{ $previewVersion->version_number }}?">
                                Restore this version
                            </button>
                        @endif
                    </div>

                    <div class="canvas" style="padding:var(--s4) 0">
                        <article class="paper" style="width:100%;min-height:0;padding:var(--s5)">
                            {!! $previewVersion->content_snapshot !!}
                        </article>
                    </div>
                </div>
            @else
                <div class="empty">
                    <p class="empty-line">Pick a version to read it, or tick two of them to compare.</p>
                    <a href="{{ route('documents.edit', $document->uuid) }}" class="btn">Back to the editor</a>
                </div>
            @endif
        </section>
    </div>
</div>

@push('styles')
<style>
    .diff-wrapper { overflow-x: auto; border: 1px solid var(--line); font-family: var(--font-mono); font-size: 13px; }
    .diff-wrapper table { width: 100%; border-collapse: collapse; }
    .diff-wrapper td, .diff-wrapper th { padding: 4px 10px; vertical-align: top; white-space: pre-wrap; word-break: break-word; border-top: 1px solid var(--line); }
    .diff-wrapper .header { background: var(--ground); color: var(--ink-soft); }
    .diff-wrapper .old { background: color-mix(in srgb, var(--danger) 12%, transparent); }
    .diff-wrapper .new { background: color-mix(in srgb, var(--accent) 14%, transparent); }
    /* --marker-chrome, not --marker: this wash sits on a CHROME surface that
       inverts with the theme, while --marker is the fixed ink in the document
       (it lands on --paper, which is white in both modes). */
    .diff-wrapper .replaced { background: color-mix(in srgb, var(--marker-chrome) 14%, transparent); }
    .diff-wrapper ins { background: color-mix(in srgb, var(--accent) 22%, transparent); text-decoration: none; }
    .diff-wrapper del { background: color-mix(in srgb, var(--danger) 20%, transparent); text-decoration: line-through; }
</style>
@endpush
