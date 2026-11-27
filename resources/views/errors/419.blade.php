@extends('errors._ecosystem-layout', ['code' => 419])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9970f" stroke-width="1.75"><circle cx="12" cy="13" r="8" stroke-linecap="round"/><path d="M12 9v4l2.5 2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 2h6" stroke-linecap="round"/></svg>
@endsection

@section('title', 'Your session timed out')
@section('message', 'For your security, this form expires after a while of inactivity. Nothing was lost — go back and try submitting again.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="btn btn-primary press">Go back and retry</a>
    <a href="/" class="btn btn-quiet press">Go home</a>
@endsection
