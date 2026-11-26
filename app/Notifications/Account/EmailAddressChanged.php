<?php

namespace App\Notifications\Account;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * Sent to the OLD address when an account's email changes (E-03), so a
 * hijacked session cannot quietly move the account to another inbox.
 */
class EmailAddressChanged extends AccountMail
{
    public function __construct(public readonly string $newAddress) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('The email address on your Dot.docs account was changed')
            ->line(new HtmlString('The email address on your Dot.docs account was changed to '.MailText::plain($this->newAddress, 80).'. Messages now go there, password resets included.'))
            ->line('If you did not do this, tell your administrator straight away.');
    }
}
