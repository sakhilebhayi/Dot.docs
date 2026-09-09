<div class="stack">
    @php
        $pendingWebhooks = $webhooks->where('status', 'pending_approval');
        $reviewedWebhooks = $webhooks->whereIn('status', ['active', 'inactive', 'rejected']);
    @endphp

    <p class="page-lede" style="margin:0">
        An endpoint here receives an HTTP POST when this document is saved or exported. A new one sends nothing
        until you approve it.
    </p>

    @if ($pendingWebhooks->isNotEmpty())
        <section aria-labelledby="hook-pending">
            <h3 class="section-title" id="hook-pending">Waiting for your approval</h3>
            <ul class="ledger">
                @foreach ($pendingWebhooks as $webhook)
                    <li class="ledger-row" style="display:block">
                        <div class="split">
                            <span class="ledger-key">
                                <span class="readout">{{ $webhook->url }}</span>
                                <span class="ledger-sub">
                                    {{ implode(', ', $webhook->events) }} — nothing has been sent to it yet.
                                </span>
                            </span>
                            <x-shell.lamp tone="signal" word="Pending" style="padding:0;border-right:0" />
                        </div>

                        @if ($rejectingWebhookId === $webhook->id)
                            <div class="field-row">
                                <label class="field-label" for="hook-reason-{{ $webhook->id }}">Why are you rejecting it?</label>
                                <input id="hook-reason-{{ $webhook->id }}" wire:model="rejectReason" type="text" class="field" />
                            </div>
                            <div class="toolbar" style="margin-top:var(--s3)">
                                <button type="button" class="btn btn-danger" wire:click="confirmReject">Reject it</button>
                                <button type="button" class="btn" wire:click="cancelReject">Cancel</button>
                            </div>
                        @else
                            <div class="toolbar" style="margin-top:var(--s3)">
                                <button type="button" class="btn btn-primary" wire:click="approveWebhook({{ $webhook->id }})">Approve it</button>
                                <button type="button" class="btn" wire:click="promptReject({{ $webhook->id }})">Reject it</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($reviewedWebhooks->isEmpty() && $pendingWebhooks->isEmpty())
        <div class="empty" style="padding:var(--s4) 0">
            <p class="empty-line">No endpoint is listening to this document.</p>
        </div>
    @elseif ($reviewedWebhooks->isNotEmpty())
        <section aria-labelledby="hook-list">
            <h3 class="section-title" id="hook-list">Endpoints</h3>
            <ul class="ledger">
                @foreach ($reviewedWebhooks as $webhook)
                    <li class="ledger-row">
                        <span class="ledger-key">
                            <span class="readout">{{ $webhook->url }}</span>
                            <span class="ledger-sub">
                                {{ implode(', ', $webhook->events) }}
                                @if ($webhook->secret) · signed with {{ Str::limit($webhook->secret, 12) }}… @endif
                                @if ($webhook->status === 'rejected') · rejected: {{ $webhook->rejected_reason }} @endif
                            </span>
                        </span>

                        @if ($webhook->status === 'rejected')
                            <x-shell.lamp tone="danger" word="Rejected" style="padding:0;border-right:0" />
                        @else
                            <button type="button" class="btn btn-sm" wire:click="toggleWebhook({{ $webhook->id }})">
                                <span class="lamp {{ $webhook->status === 'active' ? 'lamp-good' : 'lamp-idle' }}" aria-hidden="true"></span>
                                {{ $webhook->status === 'active' ? 'Active' : 'Paused' }}
                            </button>
                        @endif

                        <button type="button" class="btn btn-sm" wire:click="deleteWebhook({{ $webhook->id }})"
                                wire:confirm="Delete this webhook?">Delete</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section aria-labelledby="hook-new">
        <h3 class="section-title" id="hook-new">Add an endpoint</h3>

        <div class="field-row">
            <label class="field-label" for="hook-url">Endpoint URL</label>
            <input id="hook-url" wire:model="newUrl" type="url" class="field field-mono"
                   placeholder="https://example.com/webhook" />
            @error('newUrl')
                <p class="field-error"><span class="lamp lamp-danger" aria-hidden="true"></span> {{ $message }}</p>
            @enderror
        </div>

        <div class="field-row">
            <span class="field-label" id="hook-events">What should trigger it</span>
            <div class="toolbar" role="group" aria-labelledby="hook-events">
                <label class="field-check" for="hook-save">
                    <input id="hook-save" type="checkbox" wire:model="newEvents" value="on_save" class="field-box" />
                    <span>When the document is saved</span>
                </label>
                <label class="field-check" for="hook-export">
                    <input id="hook-export" type="checkbox" wire:model="newEvents" value="on_export" class="field-box" />
                    <span>When it is exported</span>
                </label>
            </div>
        </div>

        <label class="field-check" for="hook-secret" style="margin-top:var(--s4)">
            <input id="hook-secret" type="checkbox" wire:model="generateSecret" class="field-box" />
            <span>
                Generate an HMAC signing secret
                <span class="ledger-sub">Lets the receiver prove the request came from here.</span>
            </span>
        </label>

        <button type="button" class="btn btn-primary" style="margin-top:var(--s4)"
                wire:click="addWebhook" wire:loading.attr="disabled">Add the endpoint</button>
    </section>
</div>
