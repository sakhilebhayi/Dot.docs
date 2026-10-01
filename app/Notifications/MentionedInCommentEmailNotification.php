<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * Sent to somebody @mentioned in a comment. See CommentEmailNotification
 * for the delay and the conditions under which it is not sent at all.
 */
class MentionedInCommentEmailNotification extends CommentEmailNotification
{
    protected function bellNotification(): string
    {
        return MentionedInCommentNotification::class;
    }

    /**
     * A mention only ever reaches a participant - never "anyone who can
     * view", which on a link-shared document is every account there is.
     */
    protected function mayReceive(User $user): bool
    {
        return $this->document->isParticipant($user);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You were mentioned in "'.MailText::subject($this->document->title, 60).'"')
            ->line(new HtmlString(MailText::plain($this->comment->user->name, 40).' mentioned you in a comment on &quot;'.MailText::plain($this->document->title, 60).'&quot;.'))
            ->line(new HtmlString('&quot;'.MailText::plain($this->comment->content, 120).'&quot;'))
            ->action('View Document', route('documents.edit', $this->document->uuid));
    }
}
