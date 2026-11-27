@extends('errors._ecosystem-layout', ['code' => 404])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="11" cy="11" r="7" stroke-linecap="round"/><path d="M21 21l-4.35-4.35" stroke-linecap="round"/><path d="M9 9.5c0-1.1.9-1.75 2-1.75s2 .65 2 1.75c0 .9-.6 1.3-1.2 1.7-.5.35-.8.7-.8 1.3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="11" cy="14.75" r="0.15" fill="currentColor" stroke="none"/></svg>
@endsection

@section('title', "We couldn't find that page")
@section('message', "The page you're looking for may have moved, been renamed, or never existed. Double-check the link, or head somewhere we know works.")

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
