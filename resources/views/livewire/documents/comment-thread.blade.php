{{-- The thread owns its own scroll: the head and the composer are pinned, the
     list between them takes the leftover and scrolls, exactly like the dock.
     Without it thirty comments grew the editor page and scrolled the paper
     away with them. --}}
<div class="comment-thread">

    <div class="panel-head">
        <h2 class="section-title">
            Comments
            @if ($totalOpen > 0)
                — <x-shell.figure :value="$totalOpen" /> open
            @endif
        </h2>
        <div class="toolbar" role="group" aria-label="Comment filter">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $val => $label)
                <button type="button" class="tag" wire:click="$set('filter', '{{ $val }}')"
                        aria-pressed="{{ $filter === $val ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <ul class="list comment-thread-list">
        @forelse ($comments as $comment)
            <li class="list-row u-block" wire:key="comment-{{ $comment->id }}">
                @if ($comment->selection_text)
                    <p class="field-hint comment-quote">
                        &ldquo;{{ Str::limit($comment->selection_text, 90) }}&rdquo;
                    </p>
                @endif

                <div class="split u-gap-2">
                    <span class="list-key u-grow">
                        {{ $comment->user?->name ?? 'Former member' }}
                        <span class="list-sub">{{ $comment->created_at->diffForHumans() }}</span>
                    </span>
                    @if ($comment->isResolved())
                        <x-shell.status-word tone="good" word="Resolved" />
                    @endif
                </div>

                <p class="comment-body">
                    {!! $this->highlightMentions($comment->content) !!}
                </p>

                <div class="toolbar u-mt-2">
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
                    <ul class="list comment-replies">
                        @foreach ($comment->replies as $reply)
                            <li class="u-py-2" wire:key="reply-{{ $reply->id }}">
                                <span class="list-key">
                                    {{ $reply->user?->name ?? 'Former member' }}
                                    <span class="list-sub">{{ $reply->created_at->diffForHumans() }}</span>
                                </span>
                                <p class="comment-reply-body">
                                    {!! $this->highlightMentions($reply->content) !!}
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
                    <div class="u-mt-3" x-data="mentionInput(@entangle('replyContent'), (q) => $wire.searchMentions(q, 'reply'))">
                        <label class="sr-only" for="reply-{{ $comment->id }}">Your reply</label>
                        <textarea id="reply-{{ $comment->id }}" x-model="value" @input="handleInput($event)" rows="2" class="field"
                                  placeholder="Type @ to mention somebody" @error('replyContent') aria-invalid="true" aria-describedby="reply-{{ $comment->id }}-error" @enderror></textarea>
                        @if ($mentionTarget === 'reply' && count($mentionResults) > 0)
                            <ul class="menu-list mention-list-inline" aria-label="People to mention">
                                @foreach ($mentionResults as $u)
                                    <li><button type="button" x-on:click="insertMention(@js($u['name']))">{{ $u['name'] }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                        @error('replyContent')
                            <p class="field-error" id="reply-{{ $comment->id }}-error" role="alert">{{ $message }}</p>
                        @enderror
                        <div class="toolbar u-mt-2">
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

    <div class="panel-foot panel-foot-block">
        <div x-data="mentionInput(@entangle('newComment'), (q) => $wire.searchMentions(q))">
            <label class="field-label" for="new-comment">Add a comment</label>
            <textarea id="new-comment" x-model="value" @input="handleInput($event)"
                      @keydown.enter.ctrl.prevent="$wire.postComment()" rows="3" class="field"
                      placeholder="Type @ to mention a collaborator" @error('newComment') aria-invalid="true" aria-describedby="new-comment-error" @enderror></textarea>
            @error('newComment')
                <p class="field-error" id="new-comment-error" role="alert">{{ $message }}</p>
            @enderror

            @if ($mentionTarget === 'new' && count($mentionResults) > 0)
                <ul class="menu-list mention-list-inline" aria-label="People to mention">
                    @foreach ($mentionResults as $u)
                        <li><button type="button" x-on:click="insertMention(@js($u['name']))">{{ $u['name'] }}</button></li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="split u-mt-2">
            <span class="micro">Ctrl + Enter posts it</span>
            <button type="button" class="btn btn-primary" wire:click="postComment" wire:loading.attr="disabled">Post</button>
        </div>
    </div>
</div>

{{-- `mentionInput`'s Alpine.data() registration lives in resources/js/app.js,
     not a @push('scripts') block here: this component only ever renders
     after the writer opens comments (a Livewire AJAX update, never the
     initial full-page load, since $commentSidebarOpen defaults to false),
     and @push/@stack only ever get flushed once, at that initial load -
     see app.js's own comment on the registration for the full story. --}}
