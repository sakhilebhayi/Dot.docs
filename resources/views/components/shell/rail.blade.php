{{--
    The left panel. Where you are, what is in this document, and — at the foot,
    quietly — the facts the retired status line used to state across the top of
    the screen: product, version, word count.

    It COLLAPSES TO NOTHING rather than to a strip of initials: a 56px column of
    two-letter codes is a worse answer than no column at all, and the width is
    what the canvas is for. `state` is rendered by the layout (collapsed on the
    editor, expanded everywhere else) so the panel never flashes open before
    resources/js/shell.js runs; its single toggle lives in the top bar.

    It renders before the page's own component, so everything here comes from
    the route and from App\Support\ShellContext - never from the page.
--}}
@props(['document' => null, 'state' => 'expanded'])

@php
    $user = auth()->user();
    $isEditor = request()->routeIs('documents.edit');
@endphp

<aside id="shell-rail" class="rail" data-panel-state="{{ $state }}" aria-label="Navigator">
    {{-- Below 900px this panel covers the page, so it carries its own way out.
         It is the SAME control as the top bar's toggle - same
         `data-shell-panel-toggle` hook, same handler in resources/js/shell.js -
         so there is no second source of truth about whether the panel is open.
         CSS shows it only at the width where the panel is an overlay. --}}
    <div class="panel-overlay-head">
        <button type="button" class="btn btn-sm" data-shell-panel-toggle="rail" aria-controls="shell-rail">
            Close the panel
        </button>
    </div>

    {{-- The groups scroll; the account block below is pinned to the foot. The
         leftover height is absorbed by this scroll region rather than by a
         margin-top:auto on the foot, which is what opened the void between the
         last nav group and the user block. --}}
    <div class="rail-scroll">
    <nav class="rail-group" aria-label="Workspace">
        <div class="rail-label">Workspace</div>

        <a href="{{ route('dashboard') }}"
           class="rail-item {{ request()->routeIs('dashboard') ? 'is-current' : '' }}"
           @if (request()->routeIs('dashboard')) aria-current="page" @endif>
            <span class="rail-item-text">Dashboard</span>
        </a>

        <a href="{{ route('documents.index') }}"
           class="rail-item {{ request()->routeIs('documents.index') && ! request()->has('filter') ? 'is-current' : '' }}"
           @if (request()->routeIs('documents.index') && ! request()->has('filter')) aria-current="page" @endif>
            <span class="rail-item-text">Documents</span>
        </a>

        <a href="{{ route('files.index') }}"
           class="rail-item {{ request()->routeIs('files.index') ? 'is-current' : '' }}"
           @if (request()->routeIs('files.index')) aria-current="page" @endif>
            <span class="rail-item-text">Files</span>
        </a>

        <a href="{{ route('documents.index', ['gallery' => 1]) }}" class="rail-item">
            <span class="rail-item-text">Templates</span>
        </a>

        <a href="{{ route('documents.index', ['filter' => 'shared']) }}"
           class="rail-item {{ request()->query('filter') === 'shared' ? 'is-current' : '' }}">
            <span class="rail-item-text">Shared with me</span>
        </a>

        <a href="{{ route('slash-commands.index') }}"
           class="rail-item {{ request()->routeIs('slash-commands.index') ? 'is-current' : '' }}"
           @if (request()->routeIs('slash-commands.index')) aria-current="page" @endif>
            <span class="rail-item-text">Slash commands</span>
        </a>
    </nav>

    @if ($document)
        <nav class="rail-group" aria-label="This document">
            {{-- No title line here. The editor's own field names the document
                 (once, and editably); repeating it as static text in the panel
                 you open to reach the OUTLINE put it on screen twice. The
                 label above is the section heading this group needs. --}}
            <div class="rail-label">This document</div>

            <a href="{{ route('documents.edit', $document->uuid) }}"
               class="rail-item {{ $isEditor ? 'is-current' : '' }}"
               @if ($isEditor) aria-current="page" @endif>
                <span class="rail-item-text">Editor</span>
            </a>

            @if ($isEditor)
                {{-- Built from the headings already on the paper by shell.js;
                     it reads the DOM and never touches the editor bundle. --}}
                <div class="rail-label rail-label-sub">Outline</div>
                <ol class="rail-outline" data-shell-outline aria-live="polite" aria-busy="false">
                    <li class="rail-outline-empty">Headings appear here as you write them.</li>
                </ol>
            @endif

            <a href="{{ route('documents.history', $document->uuid) }}"
               class="rail-item {{ request()->routeIs('documents.history') ? 'is-current' : '' }}"
               @if (request()->routeIs('documents.history')) aria-current="page" @endif>
                <span class="rail-item-text">Versions</span>
                <span class="rail-item-note">
                    <x-shell.figure :value="$document->version" prefix="v" label="Version" />
                </span>
            </a>

            <a href="{{ route('documents.share', $document->uuid) }}"
               class="rail-item {{ request()->routeIs('documents.share') ? 'is-current' : '' }}"
               @if (request()->routeIs('documents.share')) aria-current="page" @endif>
                <span class="rail-item-text">Share</span>
            </a>

            <a href="{{ route('documents.settings', $document->uuid) }}"
               class="rail-item {{ request()->routeIs('documents.settings') ? 'is-current' : '' }}"
               @if (request()->routeIs('documents.settings')) aria-current="page" @endif>
                <span class="rail-item-text">Settings</span>
            </a>
        </nav>
    @endif

        @auth
            <div class="rail-group">
                @livewire('navigation-menu')
            </div>
        @endauth
    </div>

    @auth
        <div class="rail-foot">
            <span class="rail-account">
                <span class="rail-account-name">{{ $user->name }}</span>
                <span class="rail-account-team">{{ $user->currentTeam->name ?? 'Personal' }}</span>
            </span>

            {{-- The retired status line's row of figures, demoted to where it
                 belongs: a quiet line at the foot of a panel nobody has to
                 look at while writing. --}}
            <p class="rail-colophon">
                {{ config('app.name') }}@if ($document) ·
                    <x-shell.figure :value="$document->version" prefix="v" label="Version" /> ·
                    <x-shell.figure :value="$document->word_count ?? 0" /> words
                @endif
            </p>
        </div>
    @endauth
</aside>
