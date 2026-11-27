@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9970f" stroke-width="1.75"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 005.4-5.4l-2.65 2.65a1.5 1.5 0 01-2.1-2.1L14.7 6.3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', "We'll be right back")
@section('message', "This platform is briefly down for scheduled maintenance — we're making some improvements behind the scenes. Check back shortly; nothing you were working on has been lost.")

@section('actions')
    <a href="/" class="btn btn-primary press">Try again</a>
@endsection
