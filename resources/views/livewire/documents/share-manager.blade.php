<div class="page page-narrow">
    <div class="page-head">
        <div>
            <h1 class="page-title">Sharing</h1>
            <p class="page-lede">Who can reach {{ $document->title }}, and on what terms.</p>
            {{-- Where the document stands, in words, before any form (R2-6 / U-03): the link's state, its terms and
                 how many people are on it. The panels below change these; this line only reports them. --}}
            @php
                $expires = $document->share_expires_at;
                $expired = $document->is_public && $expires !== null && $expires->isPast();
                $people = $document->collaborators->count() + 1;
            @endphp
            <ul class="share-standing" aria-label="Where this document stands">
                <li>
                    <x-shell.status-word :tone="$expired ? 'danger' : ($document->is_public ? 'good' : 'idle')"
                                         :word="$expired ? 'Link expired' : ($document->is_public ? 'Link published' : 'Private')" />
                </li>
                @if ($document->is_public && $document->share_password)
                    <li><x-shell.status-word tone="good" word="Password required" /></li>
                @endif
                @if ($document->is_public && $expires !== null && ! $expired)
                    <li><x-shell.status-word tone="idle" :word="'Expires '.$expires->diffForHumans()" /></li>
                @endif
                <li><x-shell.status-word tone="idle" :word="$people === 1 ? 'Only you' : $people.' people'" /></li>
            </ul>
        </div>
        <a href="{{ route('documents.edit', $document->uuid) }}" class="btn">Back to the editor</a>
    </div>

    <section class="panel" aria-labelledby="share-link">
        <div class="panel-head">
            <h2 class="section-title" id="share-link">Public link</h2>
            {{-- Its state is said once, in the line under the page title (R2-6 / U-03). --}}
        </div>

        <div class="panel-body">
            <div class="split">
                <p class="page-lede u-m-0">
                    {{ $document->is_public
                        ? 'Anyone holding the link can read this document.'
                        : 'Only you and the people listed below can reach it.' }}
                </p>
                {{-- Two buttons with their own keys: Livewire reads wire:confirm once
                     per element, so one morphed button would keep the wrong prompt. --}}
                @if ($document->is_public)
                    <button type="button" class="btn" wire:key="publish-stop" wire:click="togglePublicLink"
                            wire:confirm="Stop publishing? The link stops working for everyone who has it.">Stop publishing</button>
                @else
                    <button type="button" class="btn btn-primary" wire:key="publish-start" wire:click="togglePublicLink">Publish a link</button>
                @endif
            </div>

            @if ($document->is_public && $publicLink)
                {{-- R2-6 / U-03: one press copies it. The clipboard API needs a secure page and permission; when it
                     is refused the field's text is selected instead, so the reader's own copy works, and the button
                     says which happened. The result is announced (role=status). --}}
                <div class="field-row" x-data="{
                        said: '',
                        async copy() {
                            try {
                                await navigator.clipboard.writeText(this.$refs.url.value);
                                this.said = 'Copied';
                            } catch (e) {
                                this.$refs.url.select();
                                this.said = 'Selected: press Ctrl+C or ⌘C';
                            }
                        },
                    }">
                    <label class="field-label" for="share-url">The link</label>
                    <div class="field-with-action">
                        <input id="share-url" type="text" value="{{ $publicLink }}" readonly class="field field-mono" x-ref="url"
                               @focus="$event.target.select()" />
                        <button type="button" class="btn" aria-describedby="share-url-said"
                                @click="copy()">Copy link</button>
                    </div>
                    <p class="field-hint" id="share-url-said" role="status" x-text="said || 'It works for anyone holding it, signed in or not.'">It works for anyone holding it, signed in or not.</p>
                </div>
            @endif

            <form wire:submit="saveSlug" class="stack">
                <div class="field-row">
                    <label class="field-label" for="share-slug">A shorter address</label>
                    <input id="share-slug" wire:model="slug" type="text" class="field field-mono"
                           placeholder="august-production" @error('slug') aria-invalid="true" aria-describedby="share-slug-error" @enderror />
                    @error('slug')
                        <p class="field-error" id="share-slug-error" role="alert">{{ $message }}</p>
                    @enderror
                    @if ($publishedLink)
                        <p class="field-hint">
                            {{ $publishedLink }}
                            @unless ($document->is_public)
                                — reserved, but nobody can open it until the link is published.
                            @endunless
                        </p>
                    @else
                        <p class="field-hint">Lower-case letters, digits and hyphens. Four characters or more.</p>
                    @endif
                </div>
                <button type="submit" class="btn">Save the address</button>
            </form>

            @if ($document->is_public && $publicLink)
                @if (session('status'))
                    <p class="note" role="status">
                        <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
                        {{ session('status') }}
                    </p>
                @endif

                <form wire:submit="saveShareOptions" class="stack">
                    <div class="field-row">
                        <label class="field-label" for="share-password">Password</label>
                        <input id="share-password" wire:model="sharePassword" type="password" class="field"
                               placeholder="{{ $showPasswordSet ? 'Type a new one to change it' : 'Leave empty for no password' }}" @error('sharePassword') aria-invalid="true" aria-describedby="share-password-error" @enderror />
                        @if ($showPasswordSet)
                            <p class="field-hint">
                                A password is set.
                                <button type="button" class="link" wire:click="clearPassword">Remove it</button>
                            </p>
                        @endif
                        @error('sharePassword')
                            <p class="field-error" id="share-password-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-row">
                        <label class="field-label" for="share-expiry">Expires at</label>
                        <input id="share-expiry" wire:model="shareExpiresAt" type="datetime-local" class="field field-mono" @error('shareExpiresAt') aria-invalid="true" aria-describedby="share-expiry-error" @enderror />
                        @error('shareExpiresAt')
                            <p class="field-error" id="share-expiry-error" role="alert">{{ $message }}</p>
                        @enderror
                        @if ($document->share_expires_at)
                            <p class="field-hint">
                                @if ($document->share_expires_at->isPast())
                                    <span class="status-word-dot status-word-dot-danger" aria-hidden="true"></span> Expired {{ $document->share_expires_at->diffForHumans() }}.
                                @else
                                    Expires {{ $document->share_expires_at->diffForHumans() }}.
                                @endif
                            </p>
                        @endif
                    </div>

                    <button type="submit" class="btn btn-primary">Save the link settings</button>
                </form>
            @endif
        </div>
    </section>

    <section class="panel" aria-labelledby="share-invite">
        <div class="panel-head">
            <h2 class="section-title" id="share-invite">Invite someone</h2>
        </div>
        <div class="panel-body">
            <form wire:submit="invite" class="stack">
                <div class="field-row">
                    <label class="field-label" for="invite-email">Email address</label>
                    <input id="invite-email" wire:model="inviteEmail" type="email" class="field" placeholder="name@example.com" @error('inviteEmail') aria-invalid="true" aria-describedby="invite-email-error" @enderror />
                    @error('inviteEmail')
                        <p class="field-error" id="invite-email-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div class="field-row">
                    <label class="field-label" for="invite-role">What they may do</label>
                    <select id="invite-role" wire:model="inviteRole" class="field">
                        <option value="viewer">Viewer — read it</option>
                        <option value="editor">Editor — write in it</option>
                        <option value="admin">Admin — write and share it</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Send the invitation</button>
                @if ($inviteNotice !== '')
                    <p class="note" role="status">
                        <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
                        {{ $inviteNotice }}
                    </p>
                @endif
            </form>
        </div>
    </section>

    {{-- Asking for access (R2-3b): people who opened a link to this document and cannot read it. --}}
    @if ($this->accessRequests->isNotEmpty() || $accessNotice !== '')
        <section class="panel" aria-labelledby="access-requests-title" id="access-requests">
            <div class="panel-head">
                <h2 class="section-title" id="access-requests-title">Asking for access</h2>
                <span class="micro"><x-shell.figure :value="$this->accessRequests->count()" label="Waiting" /></span>
            </div>
            @if ($accessNotice !== '')
                <p class="note panel-inset" role="status">
                    <span class="status-word-dot status-word-dot-good" aria-hidden="true"></span>
                    {{ $accessNotice }}
                </p>
            @endif
            <ul class="list">
                @forelse ($this->accessRequests as $ask)
                    <li class="list-row u-wrap-rows" wire:key="ask-{{ $ask->id }}">
                        <span class="list-key u-grow-md">
                            {{ $ask->requester->name }}
                            <span class="list-sub">{{ $ask->requester->email }} &middot; asked {{ $ask->created_at->diffForHumans() }}</span>
                            @if (filled($ask->message))
                                <span class="list-sub u-block u-pre-line">“{{ $ask->message }}”</span>
                            @endif
                        </span>
                        <span class="toolbar">
                            <button type="button" class="btn btn-sm" wire:click="grantAccess({{ $ask->id }}, 'viewer')">Let them read<span class="sr-only">: {{ $ask->requester->name }}</span></button>
                            <button type="button" class="btn btn-sm" wire:click="grantAccess({{ $ask->id }}, 'editor')">Let them edit<span class="sr-only">: {{ $ask->requester->name }}</span></button>
                            <button type="button" class="btn btn-quiet btn-sm" wire:click="declineAccess({{ $ask->id }})">Decline<span class="sr-only">: {{ $ask->requester->name }}</span></button>
                        </span>
                    </li>
                @empty
                    <li class="empty"><p class="empty-line">Nobody else is waiting.</p></li>
                @endforelse
            </ul>
        </section>
    @endif

    <section class="panel" aria-labelledby="share-people">
        <div class="panel-head">
            <h2 class="section-title" id="share-people">People with access</h2>
            <span class="micro">
                <x-shell.figure :value="$document->collaborators->count() + 1" label="People" />
            </span>
        </div>
        <ul class="list">
            <li class="list-row">
                <span class="list-key">
                    {{ $document->owner->name }}
                    <span class="list-sub">{{ $document->owner->email }}</span>
                </span>
                <span class="list-val">Owner</span>
            </li>
            @foreach ($document->collaborators as $collab)
                <li class="list-row">
                    <span class="list-key">
                        {{ $collab->user->name }}
                        <span class="list-sub">{{ $collab->user->email }}</span>
                    </span>
                    <span class="list-val">{{ ucfirst($collab->role) }}</span>
                    <button type="button" class="btn btn-sm" wire:click="removeCollaborator({{ $collab->id }})"
                            wire:confirm="Remove {{ $collab->user->name }}? They lose access to this document at once."
                            aria-label="Remove {{ $collab->user->name }}">Remove</button>
                </li>
            @endforeach
        </ul>
    </section>
</div>
