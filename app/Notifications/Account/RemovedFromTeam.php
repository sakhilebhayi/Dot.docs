<?php

namespace App\Notifications\Account;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/** You were removed from a team (E-04). */
class RemovedFromTeam extends AccountMail
{
    public function __construct(public readonly string $teamName) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You were removed from a team in Dot.docs')
            ->line(new HtmlString('You are no longer a member of &quot;'.MailText::plain($this->teamName, 60).'&quot;. Its documents are no longer open to you.'))
            ->line('If you think this is a mistake, ask the team\'s owner.');
    }
}
