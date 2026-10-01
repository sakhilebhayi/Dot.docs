<?php

namespace App\Livewire\Documents;

use App\Events\CommentPosted;
use App\Models\Comment;
use App\Models\Document;
use App\Models\User;
use App\Notifications\CommentPostedEmailNotification;
use App\Notifications\CommentPostedNotification;
use App\Notifications\MentionedInCommentEmailNotification;
use App\Notifications\MentionedInCommentNotification;
use App\Services\HtmlSanitizer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Notification;
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

        // One comment, one notification per person: every notification here
        // now carries an email, so the owner replying-to-themselves overlap
        // (owner AND parent author) or an @mentioned owner would otherwise
        // get two emails about the same comment. A mention is the more
        // specific notice, so it wins over the generic "commented" one.
        $mentioned = empty($mentions)
            ? collect()
            : User::whereIn('name', $mentions)->where('id', '!=', Auth::id())->get();

        $mentioned->each(function (User $user) use ($comment) {
            $this->notifySafely($user, new MentionedInCommentNotification($this->document, $comment));
            $this->notifySafely($user, new MentionedInCommentEmailNotification($this->document, $comment));
        });

        // The document owner and, on a reply, the parent comment's author.
        // whereIn() collapses the two into one row when they are the same
        // person.
        $recipientIds = collect([$this->document->owner_id, $parent?->user_id])
            ->filter()
            ->reject(fn ($id) => $id === Auth::id() || $mentioned->contains('id', $id));

        User::whereIn('id', $recipientIds)->get()->each(function (User $user) use ($comment) {
            $this->notifySafely($user, new CommentPostedNotification($this->document, $comment));
            $this->notifySafely($user, new CommentPostedEmailNotification($this->document, $comment));
        });
    }

    /**
     * CommentPostedNotification and MentionedInCommentNotification dispatch
     * synchronously now (no ShouldQueue), so a Reverb outage would
     * otherwise propagate straight out of $user->notify() - Laravel's
     * NotificationSender fires NotificationFailed but then re-throws - and
     * break posting a comment entirely. Catching per notification, same as
     * the CommentPosted::dispatch() guard above, so one recipient's
     * broadcast hiccup never stops the others from being notified.
     */
    private function notifySafely(User $user, Notification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable) {
            // Notification channel unavailable (e.g. Reverb unreachable)
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
