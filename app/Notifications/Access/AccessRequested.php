<?php

namespace App\Notifications\Access;

use App\Models\AccessRequest;
use App\Notifications\Account\AccountMail;
use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/** Somebody asked for access to your document (R2-3b / U-06). Queued, like the other account mail. */
class AccessRequested extends AccountMail
{
    public function __construct(public readonly AccessRequest $request) {}

    /** Nothing when the request was settled, or the document binned, before the worker ran. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = $this->request->fresh();
        $document = $current?->document;

        return $current !== null && $current->status === AccessRequest::PENDING && $document !== null && ! $document->trashed();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->request->document;
        $requester = $this->request->requester;
        $title = MailText::plain($document->title, 60);
        $who = MailText::plain($requester->name, 40);
        $address = MailText::plain($requester->email, 80);

        $mail = (new MailMessage)
            ->subject('Access requested: "'.MailText::subject($document->title, 60).'"')
            ->line(new HtmlString("{$who} ({$address}) asked to read &quot;{$title}&quot;. They opened a link to it but are not in its company and not shared on it."));

        if (filled($this->request->message)) {
            // A stranger's words: links are taken out (a mail client would make a bare address clickable).
            $note = (string) preg_replace('~(?:https?://|www\.)\S+~i', '[link removed]', (string) $this->request->message);
            $mail->line(new HtmlString('Their note: &quot;'.MailText::plain($note, 300).'&quot;'));
        }

        return $mail
            ->line('Nothing changes until you decide. Grant it, as a viewer or an editor, or decline it, on the document\'s Sharing page.')
            ->action('Decide on the Sharing page', route('documents.share', $document->uuid).'#access-requests');
    }
}
