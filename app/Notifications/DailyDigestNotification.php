<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $unreadCount,
        public readonly array $items,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plural = $this->unreadCount !== 1 ? 's' : '';
        $mail = (new MailMessage)
            ->subject('Your Dot.Doc daily digest')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You have **'.$this->unreadCount.' unread notification'.$plural.'** since yesterday.');

        foreach (array_slice($this->items, 0, 5) as $item) {
            $mail->line('- '.$item['message']);
        }

        if (count($this->items) > 5) {
            $mail->line('...and '.(count($this->items) - 5).' more.');
        }

        return $mail
            ->action('View notifications', url('/dashboard'))
            ->line('You can manage your notification preferences in your profile settings.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'daily_digest',
            'unread_count' => $this->unreadCount,
        ];
    }
}
