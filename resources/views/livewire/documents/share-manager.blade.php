<div class="page page-narrow">
    <div class="page-head">
        <div>
            <h1 class="page-title">Sharing</h1>
            <p class="page-lede">Who can reach {{ $document->title }}, and on what terms.</p>
        </div>
        <a href="{{ route('documents.edit', $document->uuid) }}" class="btn">Back to the editor</a>
    </div>

    <section class="panel" aria-labelledby="share-link">
        <div class="panel-head">
            <h2 class="section-title" id="share-link">Public link</h2>
            <x-shell.status-word :tone="$document->is_public ? 'good' : 'idle'"
                          :word="$document->is_public ? 'Published' : 'Private'"
                          />
        </div>

        <div class="panel-body">
            <div class="split">
                <p class="page-lede" style="margin:0">
                    {{ $document->is_public
                        ? 'Anyone holding the link can read this document.'
                        : 'Only you and the people listed below can reach it.' }}
                </p>
                <button type="button" class="btn {{ $document->is_public ? '' : 'btn-primary' }}" wire:click="togglePublicLink">
                    {{ $document->is_public ? 'Stop publishing' : 'Publish a link' }}
                </button>
            </div>

            @if ($document->is_public && $publicLink)
                <div class="field-row">
                    <label class="field-label" for="share-url">The link</label>
                    <input id="share-url" type="text" value="{{ $publicLink }}" readonly class="field field-mono" />
                    <p class="field-hint">Copy it from the field above; it works for anyone, signed in or not.</p>
                </div>
            @endif

            <form wire:submit="saveSlug" class="stack">
                <div class="field-row">
                    <label class="field-label" for="share-slug">A shorter address</label>
                    <input id="share-slug" wire:model="slug" type="text" class="field field-mono"
                           placeholder="august-production" />
                    @error('slug')
                        <p class="field-error">{{ $message }}</p>
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
                               placeholder="{{ $showPasswordSet ? 'Type a new one to change it' : 'Leave empty for no password' }}" />
                        @if ($showPasswordSet)
                            <p class="field-hint">
                                A password is set.
                                <button type="button" class="link" wire:click="clearPassword">Remove it</button>
                            </p>
                        @endif
                        @error('sharePassword')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-row">
                        <label class="field-label" for="share-expiry">Expires at</label>
                        <input id="share-expiry" wire:model="shareExpiresAt" type="datetime-local" class="field field-mono" />
                        @error('shareExpiresAt')
                            <p class="field-error">{{ $message }}</p>
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
                    <input id="invite-email" wire:model="inviteEmail" type="email" class="field" placeholder="name@example.com" />
                    @error('inviteEmail')
                        <p class="field-error">{{ $message }}</p>
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
            </form>
        </div>
    </section>

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
                    <button type="button" class="btn btn-sm" wire:click="removeCollaborator({{ $collab->id }})">Remove</button>
                </li>
            @endforeach
        </ul>
    </section>
</div>
