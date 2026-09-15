{{--
    The assistant, as the foot of the dock's Intelligence tab. It has no window
    of its own any more: the floating pill and the second fixed panel are gone,
    because nothing in this product floats except the paper.
--}}
<section class="dock-chat" aria-labelledby="dock-assistant">
    <div class="dock-section-head split">
        <h2 class="section-title" id="dock-assistant">Assistant</h2>
        @if (! empty($history))
            <button type="button" class="btn btn-quiet btn-sm" wire:click="clearHistory">Clear the thread</button>
        @endif
    </div>

    {{-- Bounded scroll region: this is the only part of the dock that
         scrolls on new turns, so "Quick passes" above stays put. The
         MutationObserver is the same auto-scroll-to-latest behaviour the
         assistant had as a floating pill, restored here against $refs.turns
         instead of $refs.messages. --}}
    <div class="dock-chat-turns" x-data x-ref="turns"
         x-init="new MutationObserver(() => {
             $refs.turns.scrollTo({
                 top: $refs.turns.scrollHeight,
                 behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
             });
         }).observe($refs.turns, { childList: true, subtree: true })">
        @if (empty($history))
            <p class="empty-line">Ask anything about this document.</p>
        @endif

        @foreach ($history as $turn)
            <div class="turn {{ $turn['role'] === 'user' ? 'turn-you' : 'turn-marker' }}">
                <span class="field-label">{{ $turn['role'] === 'user' ? 'You' : 'Assistant' }}</span>
                <p style="margin:0">{!! nl2br(e($turn['content'])) !!}</p>
            </div>
        @endforeach

        @if ($loading)
            <p aria-live="polite">
                <x-shell.status-word tone="idle" word="Thinking" />
            </p>
        @endif
    </div>

    <div class="dock-chat-foot">
        <label class="field-label" for="assistant-message">Your question</label>
        <div class="toolbar">
            <input id="assistant-message" wire:model="message" wire:keydown.enter="send" type="text"
                   class="field" style="flex:1 1 auto" placeholder="What should I look at?" />
            <button type="button" class="btn btn-primary" wire:click="send">Send</button>
        </div>
    </div>
</section>
