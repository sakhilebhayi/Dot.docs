{{--
    The navigator. Left edge of the shell, 260px, collapsible to 56px, sharing
    its right edge with the desk through a single hairline. It renders before
    the page's own component, so everything here comes from the route and from
    App\Support\ShellContext - never from the page.
--}}
@props(['document' => null])

@php
    $user = auth()->user();
    $isEditor = request()->routeIs('documents.edit');
@endphp

<aside class="rail" aria-label="Navigator">
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
            <span class="rail-initial" aria-hidden="true">DB</span>
            <span class="rail-item-text">Dashboard</span>
        </a>

        <a href="{{ route('documents.index') }}"
           class="rail-item {{ request()->routeIs('documents.index') && ! request()->has('filter') ? 'is-current' : '' }}"
           @if (request()->routeIs('documents.index') && ! request()->has('filter')) aria-current="page" @endif>
            <span class="rail-initial" aria-hidden="true">DC</span>
            <span class="rail-item-text">Documents</span>
        </a>

        <a href="{{ route('files.index') }}"
           class="rail-item {{ request()->routeIs('files.index') ? 'is-current' : '' }}"
           @if (request()->routeIs('files.index')) aria-current="page" @endif>
            <span class="rail-initial" aria-hidden="true">FL</span>
            <span class="rail-item-text">Files</span>
        </a>

        <a href="{{ route('documents.index', ['gallery' => 1]) }}" class="rail-item">
            <span class="rail-initial" aria-hidden="true">TP</span>
            <span class="rail-item-text">Templates</span>
        </a>

        <a href="{{ route('documents.index', ['filter' => 'shared']) }}"
           class="rail-item {{ request()->query('filter') === 'shared' ? 'is-current' : '' }}">
            <span class="rail-initial" aria-hidden="true">SH</span>
            <span class="rail-item-text">Shared with me</span>
        </a>

        <a href="{{ route('slash-commands.index') }}"
           class="rail-item {{ request()->routeIs('slash-commands.index') ? 'is-current' : '' }}"
           @if (request()->routeIs('slash-commands.index')) aria-current="page" @endif>
            <span class="rail-initial" aria-hidden="true">SL</span>
            <span class="rail-item-text">Slash commands</span>
        </a>
    </nav>

    @if ($document)
        <nav class="rail-group" aria-label="This document">
            <div class="rail-label">This document</div>
            <p class="rail-title">{{ $document->title ?: 'Untitled' }}</p>

            <a href="{{ route('documents.edit', $document->uuid) }}"
               class="rail-item {{ $isEditor ? 'is-current' : '' }}"
               @if ($isEditor) aria-current="page" @endif>
                <span class="rail-initial" aria-hidden="true">ED</span>
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
                <span class="rail-initial" aria-hidden="true">VR</span>
                <span class="rail-item-text">Versions</span>
                <span class="rail-item-note">
                    <x-shell.figure :value="$document->version" :width="3" label="Version" />
                </span>
            </a>

            <a href="{{ route('documents.share', $document->uuid) }}"
               class="rail-item {{ request()->routeIs('documents.share') ? 'is-current' : '' }}"
               @if (request()->routeIs('documents.share')) aria-current="page" @endif>
                <span class="rail-initial" aria-hidden="true">SR</span>
                <span class="rail-item-text">Share</span>
            </a>

            <a href="{{ route('documents.settings', $document->uuid) }}"
               class="rail-item {{ request()->routeIs('documents.settings') ? 'is-current' : '' }}"
               @if (request()->routeIs('documents.settings')) aria-current="page" @endif>
                <span class="rail-initial" aria-hidden="true">ST</span>
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
            <span class="rail-item">
                <span class="rail-initial" aria-hidden="true">{{ strtoupper(mb_substr($user->name, 0, 2)) }}</span>
                <span class="rail-item-text">
                    {{ $user->name }}
                    <span class="ledger-sub">{{ $user->currentTeam->name ?? 'Personal' }}</span>
                </span>
            </span>
            <button type="button" class="btn btn-quiet btn-sm" data-shell-rail-toggle aria-expanded="true">
                <span data-shell-rail-word>Narrow the rail</span>
            </button>
        </div>
    @endauth
</aside>
