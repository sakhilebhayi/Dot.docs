{{-- Flash banner. Tone is carried by a WORD and a dot, never by the fill. --}}
@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])
@php
    // The editor is where import, save to Files, move and save as template send
    // people back with ->with('status', …) or a `file` error, and it never showed
    // them: only flash.banner was read (EA-4 / F-05, F-06). Editor only - pages
    // such as Files show their own status and errors in place.
    $fallback = request()->routeIs('documents.edit');
    if (! $message && $fallback && session('status')) {
        $message = session('status');
        $style = 'success';
    }
    if (! $message && $fallback && isset($errors) && $errors->has('file')) {
        $message = $errors->first('file');
        $style = 'danger';
    }
@endphp

<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
     style="display: none;"
     class="note"
     :class="style === 'danger' ? 'note note-danger' : 'note'"
     role="status"
     x-show="show && message"
     x-on:banner-message.window="style = event.detail.style; message = event.detail.message; show = true;">
    <span class="status-word"
          :class="style === 'danger' ? 'status-word status-word-danger' : (style === 'success' ? 'status-word status-word-good' : 'status-word status-word-idle')">
        <span class="status-word-dot" aria-hidden="true"></span>
        <span x-text="style === 'danger' ? 'Failed' : (style === 'warning' ? 'Check' : 'Done')"></span>
    </span>
    <span x-text="message" style="flex:1 1 auto"></span>
    <button type="button" class="btn btn-quiet btn-sm" x-on:click="show = false">Dismiss</button>
</div>
