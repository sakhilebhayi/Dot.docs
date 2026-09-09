<div class="stack-tight" style="display:flex;flex-direction:column;min-height:100%">

    <div class="panel-head">
        <h2 class="section-title">
            Comments
            @if ($totalOpen > 0)
                — <x-shell.figure :value="$totalOpen" :width="2" label="Open comments" /> open
            @endif
        </h2>
        <div class="toolbar" role="group" aria-label="Comment filter">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $val => $label)
                <button type="button" class="tag" wire:click="$set('filter', '{{ $val }}')"
                        aria-pressed="{{ $filter === $val ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <ul class="ledger" style="flex:1 1 auto">
        @forelse ($comments as $comment)
            <li class="ledger-row" style="display:block" wire:key="comment-{{ $comment->id }}">
                @if ($comment->selection_text)
                    <p class="field-hint" style="border-left:2px solid var(--rule);padding-left:var(--s2);margin:0 0 var(--s2)">
                        &ldquo;{{ Str::limit($comment->selection_text, 90) }}&rdquo;
                    </p>
                @endif

                <div class="split" style="gap:var(--s2)">
                    <span class="ledger-key" style="flex:1 1 auto">
                        {{ $comment->user->name }}
                        <span class="ledger-sub">{{ $comment->created_at->diffForHumans() }}</span>
                    </span>
                    @if ($comment->isResolved())
                        <x-shell.lamp tone="good" word="Resolved" style="padding:0;border-right:0" />
                    @endif
                </div>

                <p style="margin:var(--s2) 0 0;white-space:pre-line">
                    {!! preg_replace('/@(\w+)/', '<span class="ink-marker">@$1</span>', e($comment->content)) !!}
                </p>

                <div class="toolbar" style="margin-top:var(--s2)">
                    <button type="button" class="btn btn-quiet btn-sm" wire:click="startReply({{ $comment->id }})">Reply</button>
                    @if (! $comment->isResolved())
                        <button type="button" class="btn btn-quiet btn-sm" wire:click="resolve({{ $comment->id }})">Resolve</button>
                    @else
                        <button type="button" class="btn btn-quiet btn-sm" wire:click="reopen({{ $comment->id }})">Reopen</button>
                    @endif
                    @if ($comment->user_id === auth()->id())
                        <button type="button" class="btn btn-quiet btn-sm" wire:click="delete({{ $comment->id }})"
                                wire:confirm="Delete this comment?">Delete</button>
                    @endif
                </div>

                @if ($comment->replies->isNotEmpty())
                    <ul class="ledger" style="margin-top:var(--s3);border-left:1px solid var(--rule);padding-left:var(--s3)">
                        @foreach ($comment->replies as $reply)
                            <li style="padding:var(--s2) 0" wire:key="reply-{{ $reply->id }}">
                                <span class="ledger-key">
                                    {{ $reply->user->name }}
                                    <span class="ledger-sub">{{ $reply->created_at->diffForHumans() }}</span>
                                </span>
                                <p style="margin:var(--s1) 0 0;white-space:pre-line">
                                    {!! preg_replace('/@(\w+)/', '<span class="ink-marker">@$1</span>', e($reply->content)) !!}
                                </p>
                                @if ($reply->user_id === auth()->id())
                                    <button type="button" class="btn btn-quiet btn-sm" wire:click="delete({{ $reply->id }})"
                                            wire:confirm="Delete this reply?">Delete</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($replyingTo === $comment->id)
                    <div style="margin-top:var(--s3)">
                        <label class="sr-only" for="reply-{{ $comment->id }}">Your reply</label>
                        <textarea id="reply-{{ $comment->id }}" wire:model="replyContent" rows="2" class="field"
                                  placeholder="Type @ to mention somebody"></textarea>
                        <div class="toolbar" style="margin-top:var(--s2)">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="postReply">Post the reply</button>
                            <button type="button" class="btn btn-sm" wire:click="cancelReply">Cancel</button>
                        </div>
                    </div>
                @endif
            </li>
        @empty
            <li class="empty">
                <p class="empty-line">
                    @if ($filter === 'open')
                        Nothing is open on this document.
                    @elseif ($filter === 'resolved')
                        Nothing has been resolved yet.
                    @else
                        No comments on this document yet.
                    @endif
                </p>
                <button type="button" class="btn" wire:click="$set('filter', 'all')">Show every comment</button>
            </li>
        @endforelse
    </ul>

    <div class="panel-head" style="border-bottom:0;border-top:1px solid var(--rule);display:block">
        <div x-data="mentionInput(@entangle('newComment'), (q) => $wire.searchMentions(q))">
            <label class="field-label" for="new-comment">Add a comment</label>
            <textarea id="new-comment" x-model="value" @input="handleInput($event)"
                      @keydown.enter.ctrl.prevent="$wire.postComment()" rows="3" class="field"
                      placeholder="Type @ to mention a collaborator"></textarea>

            @if (count($mentionResults) > 0)
                <ul class="menu-list" style="position:static;margin-top:var(--s2)">
                    @foreach ($mentionResults as $u)
                        <li><button type="button" x-on:click="insertMention('{{ $u['name'] }}')">{{ $u['name'] }}</button></li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="split" style="margin-top:var(--s2)">
            <span class="readout">Ctrl + Enter posts it</span>
            <button type="button" class="btn btn-primary" wire:click="postComment" wire:loading.attr="disabled">Post</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('mentionInput', (valueEntangle, onSearch) => ({
        value: valueEntangle,
        handleInput(e) {
            const val = e.target.value;
            const match = val.match(/@(\w*)$/);
            if (match) {
                onSearch(match[1]);
            } else {
                onSearch('');
            }
        },
        insertMention(name) {
            this.value = this.value.replace(/@\w*$/, '@' + name + ' ');
            onSearch('');
        }
    }));
});
</script>
@endpush
