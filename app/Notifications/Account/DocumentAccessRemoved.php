<?php

namespace App\Notifications\Account;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/** Your access to a document was removed. No link: it would only answer 403. */
class DocumentAccessRemoved extends AccountMail
{
    public function __construct(
        public readonly string $documentTitle,
        public readonly string $actorName,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your access was removed: "'.MailText::subject($this->documentTitle, 60).'"')
            ->line(new HtmlString(MailText::plain($this->actorName, 40).' removed your access to &quot;'.MailText::plain($this->documentTitle, 60).'&quot;.'))
            ->line('If you think this is a mistake, ask the document\'s owner.');
    }
}
