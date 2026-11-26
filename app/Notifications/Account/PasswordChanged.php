<?php

namespace App\Notifications\Account;

use Illuminate\Notifications\Messages\MailMessage;

/** Security notice: always sent, never optional (E-04). */
class PasswordChanged extends AccountMail
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Dot.docs password was changed')
            ->line('The password for your Dot.docs account was changed just now.')
            ->line('If you did not do this, reset your password straight away and tell your administrator.')
            ->action('Reset your password', route('password.request'));
    }
}
