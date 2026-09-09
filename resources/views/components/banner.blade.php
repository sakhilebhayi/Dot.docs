{{-- Flash banner. Tone is carried by a lamp and a word, never by the fill. --}}
@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
     style="display: none;"
     class="note"
     role="status"
     x-show="show && message"
     x-on:banner-message.window="style = event.detail.style; message = event.detail.message; show = true;">
    <span class="lamp" aria-hidden="true"
          :class="{ 'lamp-good': style === 'success', 'lamp-danger': style === 'danger', 'lamp-signal': style === 'warning', 'lamp-idle': !['success','danger','warning'].includes(style) }"></span>
    <span class="readout" x-text="style === 'danger' ? 'Failed' : (style === 'warning' ? 'Check' : 'Done')"></span>
    <span x-text="message" style="flex:1 1 auto"></span>
    <button type="button" class="btn btn-quiet btn-sm" x-on:click="show = false">Dismiss</button>
</div>
