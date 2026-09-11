@props(['team'])

<form method="POST" action="{{ route('current-team.update') }}">
    @method('PUT')
    @csrf
    <input type="hidden" name="team_id" value="{{ $team->id }}">

    <button type="submit" class="rail-item {{ Auth::user()->isCurrentTeam($team) ? 'is-current' : '' }}">
        <span class="rail-item-text">{{ $team->name }}</span>
    </button>
</form>
