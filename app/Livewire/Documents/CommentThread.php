<?php

namespace App\Livewire\Documents;

use App\Events\CommentPosted;
use App\Models\Comment;
use App\Models\Document;
use App\Models\User;
use App\Notifications\CommentPostedNotification;
use App\Notifications\MentionedInCommentNotification;
use App\Services\HtmlSanitizer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class CommentThread extends Component
{
    use AuthorizesRequests;

    public Document $document;

    public string $newComment = '';

    public ?int $replyingTo = null;

    public string $replyContent = '';

    /** User search for @mentions */
    public string $mentionQuery = '';

    public array $mentionResults = [];

    /** Filter: all | open | resolved */
    public string $filter = 'open';

    public function mount(Document $document): void
    {
        $this->document = $document;
    }

    /**
     * This component is mounted inside the editor, which has already
     * checked access - but only for that first page load. Every later
     * request from the open page starts here, so somebody removed from the
     * document stops receiving its comments at once rather than when they
     * eventually close the tab.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->document);
    }

    public function postComment(): void
    {
        $this->authorize('view', $this->document);
        $this->validate(['newComment' => 'required|string|max:2000']);

        $sanitizer = app(HtmlSanitizer::class);

        $comment = Comment::create([
            'document_id' => $this->document->id,
            'user_id' => Auth::id(),
            'content' => $sanitizer->clean($this->newComment),
        ]);

        $comment->load('user');
        $this->dispatchNotifications($comment);

        try {
            CommentPosted::dispatch($this->document, $comment);
        } catch (\Throwable) {
            // Broadcasting unavailable
        }

        $this->newComment = '';
        $this->mentionResults = [];
    }

    public function postReply(): void
    {
        $this->authorize('view', $this->document);
        $this->validate(['replyContent' => 'required|string|max:2000']);

        $parent = Comment::where('document_id', $this->document->id)
            ->findOrFail($this->replyingTo);

        $sanitizer = app(HtmlSanitizer::class);

        $comment = Comment::create([
            'document_id' => $this->document->id,
            'user_id' => Auth::id(),
            'content' => $sanitizer->clean($this->replyContent),
            'parent_id' => $parent->id,
        ]);

        $comment->load('user');
        $this->dispatchNotifications($comment, $parent);

        try {
            CommentPosted::dispatch($this->document, $comment);
        } catch (\Throwable) {
            // Broadcasting unavailable
        }

        $this->replyContent = '';
        $this->replyingTo = null;
        $this->mentionResults = [];
    }

    public function resolve(int $commentId): void
    {
        $comment = Comment::where('document_id', $this->document->id)->findOrFail($commentId);
        $this->authorize('update', $this->document);

        $comment->update(['resolved_at' => now()]);
    }

    public function reopen(int $commentId): void
    {
        $comment = Comment::where('document_id', $this->document->id)->findOrFail($commentId);
        $this->authorize('update', $this->document);

        $comment->update(['resolved_at' => null]);
    }

    public function delete(int $commentId): void
    {
        $comment = Comment::where('document_id', $this->document->id)->findOrFail($commentId);

        if ($comment->user_id !== Auth::id()) {
            $this->authorize('update', $this->document);
        }

        $comment->delete();
    }

    public function startReply(int $commentId): void
    {
        $this->replyingTo = $commentId;
        $this->replyContent = '';
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
        $this->replyContent = '';
    }

    public function searchMentions(string $query): void
    {
        $this->mentionQuery = $query;

        if (strlen($query) < 1) {
            $this->mentionResults = [];

            return;
        }

        $this->mentionResults = User::where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get(['id', 'name'])
            ->toArray();
    }

    private function dispatchNotifications(Comment $comment, ?Comment $parent = null): void
    {
        $mentions = $comment->extractMentions();

        // Notify document owner (if not commenter)
        if ($this->document->owner_id !== Auth::id()) {
            $this->document->owner->notify(
                new CommentPostedNotification($this->document, $comment)
            );
        }

        // Notify parent comment author on reply
        if ($parent && $parent->user_id !== Auth::id()) {
            $parent->user->notify(
                new CommentPostedNotification($this->document, $comment)
            );
        }

        // Notify @mentioned users
        if (! empty($mentions)) {
            User::whereIn('name', $mentions)
                ->where('id', '!=', Auth::id())
                ->get()
                ->each(fn ($user) => $user->notify(
                    new MentionedInCommentNotification($this->document, $comment)
                ));
        }
    }

    #[On('comment-posted')]
    public function onCommentPosted(): void
    {
        // Re-render triggered automatically when this event is received
    }

    public function render()
    {
        $query = Comment::where('document_id', $this->document->id)
            ->whereNull('parent_id')
            ->with(['user:id,name,profile_photo_path', 'replies.user:id,name,profile_photo_path']);

        if ($this->filter === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($this->filter === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        $comments = $query->orderByDesc('created_at')->get();
        $totalOpen = Comment::where('document_id', $this->document->id)
            ->whereNull('parent_id')->whereNull('resolved_at')->count();

        return view('livewire.documents.comment-thread', [
            'comments' => $comments,
            'totalOpen' => $totalOpen,
        ]);
    }
}
