<?php

namespace App\Livewire;

use App\Support\SignedIn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    use SignedIn;

    public int $unreadCount = 0;

    /** @var array<mixed> */
    public array $notifications = [];

    public bool $open = false;

    public function mount(): void
    {
        $this->loadNotifications();
    }

    #[On('notification-received')]
    public function loadNotifications(): void
    {
        $user = $this->actor();
        $this->unreadCount = $user->unreadNotifications()->count();
        $this->notifications = $user->notifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? 'notification',
                'message' => $this->formatMessage($n->data),
                'url' => $n->data['url'] ?? null,
                'read' => ! is_null($n->read_at),
                'time' => $n->created_at?->diffForHumans() ?? '',
            ])
            ->toArray();
    }

    public function markAllRead(): void
    {
        $this->actor()->unreadNotifications()->update(['read_at' => now()]);
        $this->loadNotifications();
    }

    public function markRead(string $id): void
    {
        $this->actor()->notifications()->where('id', $id)->update(['read_at' => now()]);
        $this->loadNotifications();
    }

    /**
     * Marks the notice read, THEN goes to it (EA-3 / E-08). A link with a
     * wire:click beside it raced the navigation: the browser often cancelled
     * the Livewire request, the notice stayed unread, and the delayed email
     * went out anyway. The address comes from the person's own stored
     * notice, never from the browser.
     */
    public function openNotice(string $id): void
    {
        $notification = $this->actor()->notifications()->where('id', $id)->first();

        if ($notification === null) {
            return;
        }

        $notification->markAsRead();
        $url = $notification->data['url'] ?? null;

        // Named openNotice(), not open(): the component's `$open` property
        // would shadow a method of that name in the browser's $wire.
        // Same scheme and host as this app, compared parsed - a plain prefix
        // test would pass "https://doc.infodot.co.za.evil.com". Anything else
        // (an old domain in an old row) goes to the dashboard, never nowhere.
        $here = parse_url(url('/'));
        $there = is_string($url) ? parse_url($url) : false;
        $sameSite = is_array($there)
            && ($there['scheme'] ?? null) === ($here['scheme'] ?? null)
            && ($there['host'] ?? null) === ($here['host'] ?? null)
            && ($there['port'] ?? null) === ($here['port'] ?? null);

        $this->redirect($sameSite ? $url : route('dashboard'));
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        if ($this->open) {
            $this->loadNotifications();
        }
    }

    /** @param array<mixed> $data */
    private function formatMessage(array $data): string
    {
        return match ($data['type'] ?? '') {
            'comment' => ($data['commenter'] ?? 'Someone').' commented on "'.($data['document_title'] ?? 'a document').'"',
            'mention' => ($data['mentioner'] ?? 'Someone').' mentioned you in "'.($data['document_title'] ?? 'a document').'"',
            'shared' => ($data['actor'] ?? 'Someone').' shared "'.($data['document_title'] ?? 'a document').'" with you',
            'access_changed' => ($data['actor'] ?? 'Someone').' changed your access to "'.($data['document_title'] ?? 'a document').'"',
            'access_requested' => ($data['actor'] ?? 'Someone').' asked for access to "'.($data['document_title'] ?? 'a document').'"',
            default => 'You have a new notification',
        };
    }

    public function render(): View
    {
        return view('livewire.notification-bell');
    }
}
