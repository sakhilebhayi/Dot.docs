<x-app-layout>
    <x-slot name="title">Request access</x-slot>

    {{-- Says nothing about the document: not its title, not whose it is (R2-3b). --}}
    <div class="page">
        <div class="page-head">
            <div>
                <h1 class="page-title">You cannot open this document yet</h1>
                <p class="page-lede">The link is real, but the document belongs to a company you have not joined and has not been shared with you. You can ask its owner for access: they decide, and you hear when they say yes.</p>
            </div>
        </div>

        @if (session('access_request') === 'sent')
            <p class="note" role="status">
                <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
                Asked. The owner has been told; this link opens the document once they say yes.
            </p>
        @elseif ($pending ?? null)
            <p class="note" role="status">
                <span class="status-word-dot" aria-hidden="true"></span>
                You asked {{ $pending->created_at->diffForHumans() }}. The owner has not decided yet; this link opens the document once they say yes.
            </p>
        @else
            <section class="panel" aria-labelledby="ask-access" style="max-width:40rem">
                <div class="panel-head">
                    <h2 class="section-title" id="ask-access">Ask for access</h2>
                </div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('documents.request-access', $uuid) }}" class="stack">
                        @csrf
                        <div class="field-row">
                            <label class="field-label" for="access-message">A note for the owner (optional)</label>
                            <textarea id="access-message" name="message" rows="3" maxlength="500" class="field"
                                      placeholder="Who you are and why you need it"
                                      @error('message') aria-invalid="true" aria-describedby="access-message-error" @enderror>{{ old('message') }}</textarea>
                            <p class="field-error" id="access-message-error" role="alert">@error('message'){{ $message }}@enderror</p>
                            <p class="field-hint">They see your name and email address with it.</p>
                        </div>
                        <div class="toolbar">
                            <button type="submit" class="btn btn-primary">Ask for access</button>
                        </div>
                    </form>
                </div>
            </section>
        @endif

        <p style="margin-top:var(--s4)"><a href="{{ route('documents.index') }}" class="btn">Back to my documents</a></p>
    </div>
</x-app-layout>
