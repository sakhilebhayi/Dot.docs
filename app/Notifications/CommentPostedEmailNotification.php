<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * Sent to the document's owner and, on a reply, to the author of the
 * comment being replied to. See CommentEmailNotification for the delay and
 * the conditions under which it is not sent at all.
 */
class CommentPostedEmailNotification extends CommentEmailNotification
{
    protected function bellNotification(): string
    {
        return CommentPostedNotification::class;
    }

    /**
     * Somebody who commented on a link-shared document can still open it,
     * so they may hear about a reply; on a private one they must still be
     * a participant.
     */
    protected function mayReceive(User $user): bool
    {
        return $this->document->is_public || $this->document->isParticipant($user);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New comment on "'.MailText::subject($this->document->title, 60).'"')
            ->line(new HtmlString(MailText::plain($this->comment->user->name, 40).' commented on &quot;'.MailText::plain($this->document->title, 60).'&quot;.'))
            ->line(new HtmlString('&quot;'.MailText::plain($this->comment->content, 120).'&quot;'))
            ->action('View Document', route('documents.edit', $this->document->uuid));
    }
}
