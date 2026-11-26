<?php

namespace App\Notifications\Account;

use App\Models\Document;
use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/** Somebody else published (or stopped publishing) a document you own. */
class DocumentPublishedByAnother extends AccountMail
{
    public function __construct(
        public readonly Document $document,
        public readonly string $actorName,
        public readonly bool $published,
    ) {}

    /** A document binned before the worker ran gets no email (SerializesModels restores trashed rows). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = $this->document->fresh();

        return $current !== null && ! $current->trashed();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = MailText::plain($this->actorName, 40);
        $title = MailText::plain($this->document->title, 60);

        return (new MailMessage)
            ->subject(($this->published ? 'Your document was published: "' : 'Your document is no longer published: "').MailText::subject($this->document->title, 60).'"')
            ->line(new HtmlString($this->published
                ? "{$who} published a read-only link to &quot;{$title}&quot;. Anyone holding the link can read it."
                : "{$who} stopped publishing &quot;{$title}&quot;. Its link no longer works."))
            ->action('Review sharing', route('documents.share', $this->document->uuid));
    }
}
