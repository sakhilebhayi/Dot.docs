{{--
    Jetstream's navigation menu, rebuilt as the foot of the navigator rail.
    The old horizontal bar is gone: the shell has one navigator, and account,
    team and notifications belong at the bottom of it.

    Livewire component (Laravel\Jetstream\Http\Livewire\NavigationMenu), so this
    view must render exactly ONE root element - see .ai/rules/livewire.md.
--}}
<div class="rail-account">
    @auth
        @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
            <div class="rail-label">Workspace</div>
            <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="rail-item">
                <span class="rail-item-text">{{ Auth::user()->currentTeam->name }}</span>
            </a>

            @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                <a href="{{ route('teams.create') }}" class="rail-item">
                    <span class="rail-item-text">Create a team</span>
                </a>
            @endcan

            @if (Auth::user()->allTeams()->count() > 1)
                @foreach (Auth::user()->allTeams() as $team)
                    @if ($team->id !== Auth::user()->currentTeam->id)
                        <x-switchable-team :team="$team" />
                    @endif
                @endforeach
            @endif
        @endif

        <div class="rail-label">Account</div>

        <div style="padding:0 10px var(--s1)">
            @livewire('notification-bell')
        </div>

        <a href="{{ route('profile.show') }}"
           class="rail-item {{ request()->routeIs('profile.show') ? 'is-current' : '' }}">
            <span class="rail-item-text">Profile</span>
        </a>

        @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
            <a href="{{ route('api-tokens.index') }}"
               class="rail-item {{ request()->routeIs('api-tokens.index') ? 'is-current' : '' }}">
                <span class="rail-item-text">API tokens</span>
            </a>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="rail-item">
                <span class="rail-item-text">Log out</span>
            </button>
        </form>
    @endauth
</div>
