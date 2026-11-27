<?php

namespace App\Notifications\Account;

use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/** You were given access to a document, or your role on it changed (E-04, F-11). */
class DocumentSharedWithYou extends AccountMail
{
    public function __construct(
        public readonly Document $document,
        public readonly string $role,
        public readonly string $actorName,
        public readonly bool $roleChanged = false,
    ) {}

    /**
     * Checked when the worker runs, not when the share was made: a document binned before then gets no email
     * (SerializesModels restores trashed rows), nor does a person whose access was removed or changed again since.
     * A later change sends its own email.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = $this->document->fresh();

        if ($current === null || $current->trashed() || ! $notifiable instanceof User) {
            return false;
        }

        return DocumentCollaborator::where('document_id', $current->id)
            ->where('user_id', $notifiable->id)
            ->value('role') === $this->role;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = MailText::plain($this->document->title, 60);
        $who = MailText::plain($this->actorName, 40);
        $role = MailText::plain($this->roleWord(), 20);

        return (new MailMessage)
            ->subject($this->roleChanged
                ? 'Your access changed on "'.MailText::subject($this->document->title, 60).'"'
                : 'A document was shared with you: "'.MailText::subject($this->document->title, 60).'"')
            ->line(new HtmlString($this->roleChanged
                ? "{$who} changed your access to &quot;{$title}&quot;. You can now {$role} it."
                : "{$who} shared &quot;{$title}&quot; with you. You can {$role} it."))
            ->action('Open the document', route('documents.edit', $this->document->uuid));
    }

    private function roleWord(): string
    {
        return match ($this->role) {
            'admin' => 'edit and share',
            'editor' => 'edit',
            default => 'read',
        };
    }
}
