{{-- R-61: shown with a 503 when InfoDot has switched Dot.docs off (maintenance or unavailable). It says so plainly and reads
     nothing of the company's work: the documents already here are kept exactly as they are. Not a purchase or permission problem. --}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c9970f" stroke-width="1.75"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 005.4-5.4l-2.65 2.65a1.5 1.5 0 01-2.1-2.1L14.7 6.3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'Dot.docs is not available right now')
@section('message', ($message ?? 'Dot.docs is temporarily unavailable.').' The documents already here are kept as they are.')

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #1f1b14; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Try again</a>
@endsection
