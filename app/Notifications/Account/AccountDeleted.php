<?php

namespace App\Notifications\Account;

use App\Actions\Jetstream\DeleteUser;
use Illuminate\Notifications\Messages\MailMessage;

/** Confirms an account deletion and says what happened to the documents (E-04, EA-2 rules). */
class AccountDeleted extends AccountMail
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Dot.docs account was deleted')
            ->line('Your Dot.docs account was deleted just now.')
            ->line('Documents you owned in a company team now belong to that team\'s owner. Your personal documents can be recovered by an administrator for '.DeleteUser::RECOVERABLE_DAYS.' days; after that they are deleted for good.')
            ->line('If you did not do this, tell your administrator straight away.');
    }
}
