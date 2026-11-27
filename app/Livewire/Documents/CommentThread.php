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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        if ($this->tooManyComments('newComment')) {
            return;
        }

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

        if ($this->tooManyComments('replyContent')) {
            return;
        }

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

    /** Which box the suggestions belong to: the new comment, or the reply being written (R2-3 / F-12). */
    public string $mentionTarget = 'new';

    public function searchMentions(string $query, string $target = 'new'): void
    {
        $this->mentionQuery = $query;
        $this->mentionTarget = $target === 'reply' ? 'reply' : 'new';

        if (strlen($query) < 1) {
            $this->mentionResults = [];

            return;
        }

        // Only the people a mention can actually reach - see
        // dispatchNotifications(). Offering every account on the platform
        // here listed strangers by name and promised a notification that
        // is never sent.
        $this->mentionResults = $this->document->participants()
            ->filter(fn (User $user) => $user->id !== Auth::id() && mb_stripos($user->name, $query) !== false)
            ->take(5)
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
    }

    /**
     * Every comment can now send email, and registration is open: without
     * a ceiling one account could be scripted into a mail cannon. Twenty a
     * minute is far above anyone actually writing.
     */
    private function tooManyComments(string $field): bool
    {
        $key = 'comment:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError($field, 'You are posting very quickly. Wait a moment and try again.');

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    /**
     * One comment, one notification per person, and only to people who can
     * still open the document.
     *
     * Every notification carries an email now, so the overlaps that used to
     * be merely untidy would each be a second email about the same comment:
     * the owner replying-to-themselves case (owner AND parent author), or an
     * owner who is also @mentioned. A mention is the more specific notice,
     * so it wins over the generic "commented" one.
     */
    private function dispatchNotifications(Comment $comment, ?Comment $parent = null): void
    {
        $participants = $this->document->participants();

        $mentioned = $this->mentionedAmong($participants, $comment)
            ->reject(fn (User $user) => $user->id === Auth::id());

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

        // The author of an old comment may have lost access since writing
        // it, so they must still be able to open the document: a
        // participant. Publishing does not count - only participants can
        // open the editor the notification links to.
        User::whereIn('id', $recipientIds)
            ->get()
            ->filter(fn (User $user) => $participants->contains('id', $user->id))
            ->each(function (User $user) use ($comment) {
                $this->notifySafely($user, new CommentPostedNotification($this->document, $comment));
                $this->notifySafely($user, new CommentPostedEmailNotification($this->document, $comment));
            });
    }

    /**
     * Who the comment @mentions, out of the document's participants.
     *
     * Matched against each participant's FULL name, longest first, instead
     * of pulling `@word` out of the text and looking that word up across
     * every account: names are free text and not unique, so `@Thandi
     * Mokoena` used to resolve to nobody (the word was "Thandi"), and
     * `@Sam` to every Sam on the platform, including ones with nothing to
     * do with this document. Longest first so "Sam Smith" is not also read
     * as a mention of a participant called just "Sam".
     *
     * @param  Collection<int, User>  $participants
     * @return Collection<int, User>
     */
    private function mentionedAmong(Collection $participants, Comment $comment): Collection
    {
        $text = html_entity_decode(strip_tags($comment->content), ENT_QUOTES | ENT_HTML5);

        $mentionedNames = $participants
            ->pluck('name')
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $name) => mb_strlen($name))
            ->filter(function (string $name) use (&$text) {
                $pattern = '/@'.preg_quote($name, '/').'(?![\p{L}\p{N}_])/u';

                if (preg_match($pattern, $text) !== 1) {
                    return false;
                }

                $text = (string) preg_replace($pattern, ' ', $text);

                return true;
            });

        return $participants
            ->filter(fn (User $user) => $mentionedNames->contains($user->name))
            ->values();
    }

    /**
     * The bell notifications write their row and broadcast inside this
     * request (no ShouldQueue, and a sync broadcast connection), so an
     * unreachable Reverb would otherwise propagate straight out of
     * $user->notify() - NotificationSender fires NotificationFailed but
     * then re-throws - and break posting a comment entirely. Catching per
     * notification, same as the CommentPosted::dispatch() guard above, so
     * one recipient's broadcast hiccup never stops the others.
     */
    private function notifySafely(User $user, Notification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            // Notification channel unavailable (e.g. Reverb unreachable).
            // Logged, never silent (EA-3 / E-02): the Guardian error-rate
            // check reads the log. The class only - no address, no text.
            Log::warning('Comment notification could not be sent.', ['notice' => $notification::class, 'error' => $e::class]);
        }
    }

    #[On('comment-posted')]
    public function onCommentPosted(): void
    {
        // Re-render triggered automatically when this event is received
    }

    /**
     * A comment's text, escaped, with each @mention of a participant highlighted - matched by FULL name, longest
     * first, exactly as mentionedAmong() decides who is notified (R2-3 / F-12). The page used to highlight any
     * `@word`, so "@Thandi Mokoena" lit up only "@Thandi", and a name that notifies nobody looked like it did.
     */
    public function highlightMentions(string $content): string
    {
        $html = e($content);
        $names = $this->participantNames();

        if ($names === []) {
            return $html;
        }

        // ONE pass over an alternation, longest name first: a second pass for "Thandi" would otherwise find it again
        // inside the span already made for "Thandi Mokoena" (and mentionedAmong() does not notify her for it). A
        // callback, not a replacement string, so a name holding "$1" or a backslash is shown as written.
        $pattern = '/@('.implode('|', array_map(fn (string $name) => preg_quote(e($name), '/'), $names)).')(?![\p{L}\p{N}_])/u';

        return (string) preg_replace_callback($pattern, fn (array $m) => '<span class="mention">@'.$m[1].'</span>', $html);
    }

    /** @return list<string> participants' names, longest first */
    private function participantNames(): array
    {
        return $this->participantNamesCache ??= $this->document->participants()
            ->pluck('name')->filter()->unique()
            ->sortByDesc(fn (string $name) => mb_strlen($name))
            ->values()->all();
    }

    /** @var list<string>|null */
    private ?array $participantNamesCache = null;

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
