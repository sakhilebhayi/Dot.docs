@extends('errors._ecosystem-layout', ['code' => 403])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d2232a" stroke-width="1.75"><rect x="5" y="11" width="14" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 11V7a4 4 0 118 0v4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="15.5" r="1.1" fill="#d2232a" stroke="none"/></svg>
@endsection

@section('title', "You don't have access to this")
@section('message', "Either this belongs to a different team, needs a role you don't currently have, or requires signing in first. If you think this is wrong, check with whoever owns this workspace.")

@section('actions')
    <a href="/" class="btn btn-primary press">Go home</a>
    @auth
    <a href="{{ route('dashboard') }}" class="btn btn-quiet press">Go to dashboard</a>
    @else
        @if (Route::has('login'))
    <a href="{{ \App\Support\CentralSignIn::login() }}" class="btn btn-quiet press">Sign in</a>
        @endif
    @endauth
@endsection
