<div>
    {{-- The assistant's own dock-corner tab. No colour tell: it is a square nib
         plus a word, on the same raised desk tone as the rest of the chrome. --}}
    <button type="button" wire:click="toggle" class="btn assistant-tab"
            aria-expanded="{{ $open ? 'true' : 'false' }}">
        <span class="lamp lamp-marker" aria-hidden="true"></span>
        {{ $open ? 'Close the assistant' : 'Ask the assistant' }}
    </button>

    @if ($open)
        <div class="assistant-panel" role="dialog" aria-label="Assistant chat">
            <div class="sheet-head">
                <h2 class="section-title">Assistant</h2>
                <button type="button" class="btn btn-quiet btn-sm" wire:click="clearHistory">Clear the thread</button>
            </div>

            <div class="sheet-body" x-data x-ref="messages"
                 x-init="new MutationObserver(() => { $refs.messages.scrollTop = $refs.messages.scrollHeight; }).observe($refs.messages, { childList: true, subtree: true })">
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
                    <p class="toolbar" aria-live="polite">
                        <span class="lamp lamp-signal" aria-hidden="true"></span>
                        <span class="readout">Thinking</span>
                    </p>
                @endif
            </div>

            <div class="sheet-foot" style="display:block">
                <label class="sr-only" for="assistant-message">Your question</label>
                <div class="toolbar">
                    <input id="assistant-message" wire:model="message" wire:keydown.enter="send" type="text"
                           class="field" style="flex:1 1 auto" placeholder="What should I look at?" />
                    <button type="button" class="btn btn-primary" wire:click="send">Send</button>
                </div>
            </div>
        </div>
    @endif
</div>
