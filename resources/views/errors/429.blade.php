@extends('errors._ecosystem-layout', ['code' => 429])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9970f" stroke-width="1.75"><circle cx="12" cy="13" r="8" stroke-linecap="round"/><path d="M10 10v6M14 10v6" stroke-linecap="round"/><path d="M9 2h6" stroke-linecap="round"/></svg>
@endsection

@section('title', 'Slow down a little')
@section('message', "You've made a few too many requests in a short space of time — a safeguard that protects everyone's access. Wait a minute and try again.")

@section('actions')
    <a href="/" class="btn btn-primary press">Go home</a>
@endsection
