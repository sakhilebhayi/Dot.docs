@php
    /*
     * The shared Dot header (dot-ui 0.6.0) on top of the shell's own rail. The rail is the platform's navigation, so the
     * header carries no primary nav (`dh--nonav`) and its menu button opens the rail (adopt mode, `drawer-target`).
     *
     * Shortcut ownership: Ctrl/Cmd+K belongs to the EDITOR's command palette on the editor route (resources/js/editor/
     * commands). Everywhere else it focuses this search. On the editor the pill prints no hint and binds no key, so the
     * two never fight; the search is still one tap away.
     */
    $user = auth()->user();
    $team = $user?->currentTeam;
    $company = $team && ! $team->personal_team ? ($team->infodot_team_uuid ?: null) : null;

    try {
        $switcherModel = \Dot\Ui\PlatformSwitcher::fromConfig('docs')->for($company);
    } catch (\Throwable $e) {
        // A host with no ecosystem config must still show the switcher, in its plain state, never nothing.
        $switcherModel = \Dot\Ui\PlatformSwitcher::plain('docs');
    }

    $account = null;
    if ($user) {
        $links = [];
        if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $team) {
            $links[] = ['heading' => 'Company'];
            $links[] = ['label' => $team->name, 'href' => route('teams.show', $team->id)];
            foreach ($user->allTeams() as $other) {
                if ($other->id !== $team->id) {
                    $links[] = ['label' => 'Switch to '.$other->name, 'form' => [
                        'action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $other->id],
                    ]];
                }
            }
            if (Gate::forUser($user)->check('create', Laravel\Jetstream\Jetstream::newTeamModel())) {
                $links[] = ['label' => 'Create a team', 'href' => route('teams.create')];
            }
            $links[] = ['rule' => true];
        }
        $links[] = ['label' => 'Profile', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')];
        if (Laravel\Jetstream\Jetstream::hasApiFeatures()) {
            $links[] = ['label' => 'API tokens', 'href' => route('api-tokens.index'), 'current' => request()->routeIs('api-tokens.index')];
        }
        $account = [
            'name' => $user->name, 'email' => $user->email, 'team' => $team?->name ?? 'Personal',
            'avatar' => $user->profile_photo_path ? $user->profile_photo_url : null,
            'links' => $links, 'signOut' => ['action' => route('logout')], 'theme' => true,
        ];
    }
@endphp
<x-dot.header class="shell-header" id="dh"
              drawer-target="shell-rail" :sticky="false"
              brand-href="{{ route('dashboard') }}" brand-label="Dot.docs home"
              :account="$account" menu-label="Open the menu" skip="#canvas">
    <x-slot:brand>
        <img class="dh-logo-day" src="{{ asset('images/mark.png') }}" alt="" width="36" height="36">
        <img class="dh-logo-night" src="{{ asset('images/mark-light.png') }}" alt="" width="36" height="36">
        <span class="dh-brand__name">Dot.docs</span>
    </x-slot:brand>

    <x-slot:status>
        {{-- Desktop only: the rail's own toggle (phones use the menu button). The save word only ever reports what the page
             told it (resources/js/shell.js); it is empty and hidden outside the editor. --}}
        <button type="button" class="shell-rail-toggle" data-shell-panel-toggle="rail" aria-controls="shell-rail"
                aria-expanded="{{ $railState === 'expanded' ? 'true' : 'false' }}">
            <span data-shell-rail-word>{{ $railState === 'expanded' ? 'Hide the panel' : 'Show the panel' }}</span>
        </button>
        <x-shell.status-word tone="idle" word="Ready" id="shell-save" class="topbar-status" aria-live="polite"
                             :data-shell-save-owner="$isEditor ? true : null" />
    </x-slot:status>

    <x-slot:search>
        @auth
            @livewire('header-search', ['editor' => $isEditor])
        @endauth
    </x-slot:search>

    <x-slot:tools>
        <button type="button" class="shell-dock-toggle" data-shell-panel-toggle="dock" aria-controls="shell-dock"
                aria-expanded="{{ $dockState === 'expanded' ? 'true' : 'false' }}">
            <span data-shell-dock-word>{{ $dockState === 'expanded' ? 'Hide the tools' : 'Show the tools' }}</span>
        </button>
        <x-dot.switcher platform="docs" :model="$switcherModel" :company-name="$team?->name" logos="/dot-ui/img/platforms" />
        @auth
            @livewire('notification-bell')
        @endauth
    </x-slot:tools>
</x-dot.header>
