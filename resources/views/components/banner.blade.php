{{-- Flash banner. Tone is carried by a WORD and a dot, never by the fill. --}}
@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

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
