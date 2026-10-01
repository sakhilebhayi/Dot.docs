# Real-Time Notifications & Smart Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the notification bell dispatch instantly (no queue dependency), add a delayed email that cancels itself if the recipient already saw the bell notification, and fix the production delivery infrastructure (mail driver, queue processing, scheduler) that currently makes none of this actually run.

**Architecture:** `CommentPostedNotification` and `MentionedInCommentNotification` stop implementing `ShouldQueue`, so their `database` + `broadcast` channels fire synchronously inside the HTTP request that creates the comment. Two new sibling notification classes (`CommentPostedEmailNotification`, `MentionedInCommentEmailNotification`) handle email only, remain `ShouldQueue`, and use Laravel's native `withDelay()` (~2 minutes) and `shouldSend()` (cancel if the matching bell notification's `read_at` is already set) hooks. The once-daily digest is deleted outright. A new `Schedule::command('queue:work --stop-when-empty')->everyMinute()` entry replaces it, because this shared host has no `sudo`/systemd access for a persistent worker.

**Tech Stack:** Laravel 13.32, PHPUnit, Livewire, SQLite (both test and production).

## Global Constraints

- Every change must be programmatically tested (CLAUDE.md Test Enforcement) — run the affected test file after each task.
- Run `vendor/bin/pint --dirty --format agent` after any PHP changes, before considering a task done.
- Follow existing code conventions in sibling files (constructor property promotion, explicit return types, curly braces always).
- Do not create verification scripts or use tinker for anything a test already proves.
- Before editing any file, open `.ai/rules/index.md` and read every rule file whose globs match it (`app/**` -> `.ai/rules/app.md`).
- This plan does NOT touch production `.env` or add a cPanel cron entry — those are manual server-side actions handed to the user separately, after this code is merged and reviewed, not part of any task here.

---

## Task 1: Retire the daily digest; replace its schedule entry with cron-driven queue processing

**Files:**
- Modify: `routes/console.php`
- Delete: `app/Notifications/DailyDigestNotification.php`
- Delete: `app/Console/Commands/SendDailyDigest.php`
- Test: `tests/Feature/ScheduleTest.php` (new)

**Interfaces:**
- Produces: a `Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->withoutOverlapping(2)` entry in `routes/console.php`, which Task 2/3's synchronous-bell + queued-email design relies on to actually process the queued email jobs in production. No other task consumes anything from this one directly — it's independent infrastructure cleanup.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ScheduleTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_the_queue_is_scheduled_to_run_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events());

        $queueEvent = $events->first(fn ($event) => str_contains($event->command ?? '', 'queue:work'));

        $this->assertNotNull($queueEvent, 'Expected a scheduled queue:work command.');
        $this->assertStringContainsString('--stop-when-empty', $queueEvent->command);
        $this->assertSame('* * * * *', $queueEvent->getExpression());
        $this->assertTrue($queueEvent->withoutOverlapping);
        $this->assertSame(2, $queueEvent->expiresAt, 'The overlap lock must expire in minutes, not the 24h default.');
    }

    public function test_the_daily_digest_is_no_longer_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertFalse(
            $events->contains(fn ($event) => str_contains($event->command ?? '', 'notifications:digest')),
            'The daily digest should no longer be scheduled.'
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/ScheduleTest.php`
Expected: `test_the_queue_is_scheduled_to_run_every_minute` FAILS (no `queue:work` event exists yet). `test_the_daily_digest_is_no_longer_scheduled` currently PASSES by accident (it only fails once we see what's really scheduled) — that's fine, it'll stay green through Step 3.

- [ ] **Step 3: Replace the schedule entry**

Edit `routes/console.php` — replace this line:

```php
Schedule::command('notifications:digest')->dailyAt('08:00');
```

with:

```php
// This shared host has no sudo/systemd access, so a persistent queue
// worker (bootstrap.yml's deploy/queue-worker.service) can never actually
// be installed here. Running the queue in short cron-driven bursts is the
// standard Laravel answer for shared hosting without daemon access:
// --stop-when-empty exits as soon as the queue drains instead of idling,
// and withoutOverlapping() stops a slow-draining minute from starting a
// second worker on top of it. The lock expires after 2 minutes, not the
// 24-hour default: a shared host can kill a process mid-run without it
// ever releasing its lock, and a stuck 24h lock would silently stop every
// queued email for a day.
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping(2);
```

- [ ] **Step 4: Delete the digest notification and command**

```bash
rm app/Notifications/DailyDigestNotification.php
rm app/Console/Commands/SendDailyDigest.php
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/ScheduleTest.php`
Expected: both tests PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/console.php tests/Feature/ScheduleTest.php
git rm app/Notifications/DailyDigestNotification.php app/Console/Commands/SendDailyDigest.php
git commit -m "feat(notifications): retire daily digest, run the queue via cron instead

This shared cPanel account has no sudo/systemd access, so the
persistent queue-worker service bootstrap.yml installs can never
actually run here - confirmed live, 35 jobs were stuck unprocessed
with zero worker running. A cron-driven \`queue:work --stop-when-empty\`
every minute replaces it. The once-daily digest becomes unnecessary
once per-event email (next tasks) actually works.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 2: Make the bell dispatch synchronously, with resilient broadcast failure handling

**Files:**
- Modify: `app/Notifications/CommentPostedNotification.php`
- Modify: `app/Notifications/MentionedInCommentNotification.php`
- Modify: `app/Livewire/Documents/CommentThread.php`
- Test: `tests/Feature/Documents/CommentNotificationsTest.php` (new)

**Interfaces:**
- Consumes: `App\Documents\DocumentStore::create(User $owner, string $title, ?array $json = null, array $attrs = [])` (existing) to build a document with `['is_public' => true]` so a non-owner can view/comment without team/collaborator setup.
- Produces: `CommentThread::notifySafely(User $user, \Illuminate\Notifications\Notification $notification): void` — a private helper. Task 3 reuses this exact method for the two new email notifications; do not rename it.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Documents/CommentNotificationsTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CommentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Queue::fake() swallows anything pushed to the queue without running
     * it, so the bell row can only exist here if it was written inline.
     */
    public function test_posting_a_comment_notifies_the_document_owner_immediately_without_queueing(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment');

        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_mentioning_a_user_in_a_comment_notifies_them_immediately(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $mentioned = User::factory()->withPersonalTeam()->create(['name' => 'mentioned_user']);
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @mentioned_user check this out')
            ->call('postComment');

        $this->assertSame(1, $mentioned->fresh()->unreadNotifications()->count());
    }

    public function test_a_broadcast_failure_does_not_prevent_the_comment_from_posting(): void
    {
        Notification::extend('broadcast', fn () => new class
        {
            public function send($notifiable, $notification): void
            {
                throw new \RuntimeException('Reverb unreachable');
            }
        });

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('comments', ['content' => 'Hello there']);
        // `database` is listed before `broadcast` in via(), so the bell row
        // is already written by the time the broadcast throws.
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Documents/CommentNotificationsTest.php`
Expected: all three FAIL. The first two fail with a count of 0 (the notifications are `ShouldQueue` today, so `Queue::fake()` swallows them and no bell row is written). The third fails with `RuntimeException: Reverb unreachable` (the testing queue connection is `sync`, so the queued notification runs inline and nothing catches the throwing broadcast channel).

- [ ] **Step 3: Remove `ShouldQueue` and dead `toMail()` from both bell notifications**

Replace the full contents of `app/Notifications/CommentPostedNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The bell only - database + broadcast, dispatched synchronously (this
 * class does NOT implement ShouldQueue) so the bell updates the instant a
 * comment is posted, with no queue hop in between. The email side lives in
 * CommentPostedEmailNotification, which IS queued and delayed.
 */
class CommentPostedNotification extends Notification
{
    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'comment',
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'document_title' => $this->document->title,
            'comment_id' => $this->comment->id,
            'commenter' => $this->comment->user->name,
            'excerpt' => Str::limit($this->comment->content, 80),
            'url' => route('documents.edit', $this->document->uuid),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
```

Replace the full contents of `app/Notifications/MentionedInCommentNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The bell only - see CommentPostedNotification's docblock for why this
 * doesn't implement ShouldQueue. MentionedInCommentEmailNotification
 * handles the delayed, cancellable email.
 */
class MentionedInCommentNotification extends Notification
{
    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mention',
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'document_title' => $this->document->title,
            'comment_id' => $this->comment->id,
            'mentioner' => $this->comment->user->name,
            'excerpt' => Str::limit($this->comment->content, 80),
            'url' => route('documents.edit', $this->document->uuid),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
```

- [ ] **Step 4: Add the `notifySafely()` guard in `CommentThread.php`**

In `app/Livewire/Documents/CommentThread.php`, add this import alongside the existing ones (after `use App\Notifications\MentionedInCommentNotification;`):

```php
use Illuminate\Notifications\Notification;
```

Replace the existing `dispatchNotifications()` method:

```php
    private function dispatchNotifications(Comment $comment, ?Comment $parent = null): void
    {
        $mentions = $comment->extractMentions();

        // Notify document owner (if not commenter)
        if ($this->document->owner_id !== Auth::id()) {
            $this->document->owner->notify(
                new CommentPostedNotification($this->document, $comment)
            );
        }

        // Notify parent comment author on reply
        if ($parent && $parent->user_id !== Auth::id()) {
            $parent->user->notify(
                new CommentPostedNotification($this->document, $comment)
            );
        }

        // Notify @mentioned users
        if (! empty($mentions)) {
            User::whereIn('name', $mentions)
                ->where('id', '!=', Auth::id())
                ->get()
                ->each(fn ($user) => $user->notify(
                    new MentionedInCommentNotification($this->document, $comment)
                ));
        }
    }
```

with:

```php
    private function dispatchNotifications(Comment $comment, ?Comment $parent = null): void
    {
        $mentions = $comment->extractMentions();

        // Notify document owner (if not commenter)
        if ($this->document->owner_id !== Auth::id()) {
            $this->notifySafely($this->document->owner, new CommentPostedNotification($this->document, $comment));
        }

        // Notify parent comment author on reply
        if ($parent && $parent->user_id !== Auth::id()) {
            $this->notifySafely($parent->user, new CommentPostedNotification($this->document, $comment));
        }

        // Notify @mentioned users
        if (! empty($mentions)) {
            User::whereIn('name', $mentions)
                ->where('id', '!=', Auth::id())
                ->get()
                ->each(function (User $user) use ($comment) {
                    $this->notifySafely($user, new MentionedInCommentNotification($this->document, $comment));
                });
        }
    }

    /**
     * CommentPostedNotification and MentionedInCommentNotification dispatch
     * synchronously now (no ShouldQueue), so a Reverb outage would
     * otherwise propagate straight out of $user->notify() - Laravel's
     * NotificationSender logs a NotificationFailed event but then
     * re-throws (confirmed by reading the framework source) - and break
     * posting a comment entirely. Catching per-notification, same as
     * CommentPosted::dispatch() below, so one recipient's broadcast
     * hiccup never stops the others from being notified.
     */
    private function notifySafely(User $user, Notification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable) {
            // Notification channel unavailable (e.g. Reverb unreachable)
        }
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/CommentNotificationsTest.php`
Expected: all three PASS.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests pass (no regression from removing `ShouldQueue`/`toMail()` elsewhere — search the codebase first: `grep -rn "CommentPostedNotification\|MentionedInCommentNotification" app/ tests/` to confirm nothing else references the removed `toMail()` method or expects these classes to be queueable).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/CommentPostedNotification.php app/Notifications/MentionedInCommentNotification.php app/Livewire/Documents/CommentThread.php tests/Feature/Documents/CommentNotificationsTest.php
git commit -m "feat(notifications): dispatch the bell synchronously, not through the queue

CommentPostedNotification and MentionedInCommentNotification no longer
implement ShouldQueue - database + broadcast now fire inline, during
the same request that creates the comment, so the bell updates
instantly regardless of whether anything is processing the queue.
toMail() is removed as dead code (via() never included 'mail'); the
email side moves to a dedicated notification in the next commit.

Removing ShouldQueue means a Reverb outage would otherwise propagate
out of \$user->notify() and break posting a comment - CommentThread's
new notifySafely() wrapper catches that per-recipient, matching the
existing CommentPosted::dispatch() guard.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 3: Add delayed, cancellable email notifications

**Files:**
- Create: `app/Notifications/CommentPostedEmailNotification.php`
- Create: `app/Notifications/MentionedInCommentEmailNotification.php`
- Modify: `app/Livewire/Documents/CommentThread.php`
- Test: `tests/Feature/Documents/CommentEmailNotificationsTest.php` (new)

**Interfaces:**
- Consumes: `CommentThread::notifySafely()` from Task 2 (unchanged signature).
- Consumes: the `notifications` table's `data` JSON column and `read_at` timestamp, exactly as `App\Livewire\NotificationBell::markRead()`/`markAllRead()` already write them (no new columns, no migration).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Documents/CommentEmailNotificationsTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\CommentPostedEmailNotification;
use App\Notifications\MentionedInCommentEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CommentEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function commentOn(User $owner): Comment
    {
        $doc = app(DocumentStore::class)->create($owner, 'Doc');

        return Comment::create([
            'document_id' => $doc->id,
            'user_id' => $owner->id,
            'content' => 'hello',
        ]);
    }

    private function seedBellNotification(User $user, string $type, int $commentId, bool $read): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\CommentPostedNotification',
            'data' => ['type' => $type, 'comment_id' => $commentId],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_comment_email_is_not_sent_if_the_bell_notification_has_already_been_read(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'comment', $comment->id, read: true);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);

        $this->assertFalse($notification->shouldSend($owner, 'mail'));
    }

    public function test_comment_email_is_sent_if_the_bell_notification_is_still_unread(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'comment', $comment->id, read: false);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);

        $this->assertTrue($notification->shouldSend($owner, 'mail'));
    }

    public function test_mention_email_is_not_sent_if_the_bell_notification_has_already_been_read(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'mention', $comment->id, read: true);

        $notification = new MentionedInCommentEmailNotification($comment->document, $comment);

        $this->assertFalse($notification->shouldSend($owner, 'mail'));
    }

    public function test_email_delay_is_about_two_minutes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);
        $delay = $notification->withDelay($owner)['mail'];

        $this->assertTrue($delay->greaterThan(now()->addMinutes(1)));
        $this->assertTrue($delay->lessThanOrEqualTo(now()->addMinutes(3)));
    }

    public function test_posting_a_comment_queues_an_email_notification_for_the_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)
            ->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment');

        Notification::assertSentTo($owner, CommentPostedEmailNotification::class);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Documents/CommentEmailNotificationsTest.php`
Expected: FAIL — `CommentPostedEmailNotification`/`MentionedInCommentEmailNotification` classes don't exist yet.

- [ ] **Step 3: Create the email notification classes**

Create `app/Notifications/CommentPostedEmailNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The email half of a comment notification. CommentPostedNotification
 * handles the instant bell update; this one is queued with a short delay
 * and cancels itself if the recipient has already read that bell
 * notification by the time it's due to send - so an active user never
 * gets double-notified for the same comment.
 */
class CommentPostedEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function withDelay(object $notifiable): array
    {
        return ['mail' => now()->addMinutes(2)];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable->notifications()
            ->where('data->type', 'comment')
            ->where('data->comment_id', $this->comment->id)
            ->whereNotNull('read_at')
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New comment on "'.$this->document->title.'"')
            ->line($this->comment->user->name.' commented on your document.')
            ->line('"'.Str::limit($this->comment->content, 120).'"')
            ->action('View Document', route('documents.edit', $this->document->uuid));
    }
}
```

Create `app/Notifications/MentionedInCommentEmailNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The email half of a mention notification - see
 * CommentPostedEmailNotification's docblock for the delay/cancel mechanism.
 */
class MentionedInCommentEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly Comment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function withDelay(object $notifiable): array
    {
        return ['mail' => now()->addMinutes(2)];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable->notifications()
            ->where('data->type', 'mention')
            ->where('data->comment_id', $this->comment->id)
            ->whereNotNull('read_at')
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->comment->user->name.' mentioned you in "'.$this->document->title.'"')
            ->line($this->comment->user->name.' mentioned you in a comment.')
            ->line('"'.Str::limit($this->comment->content, 120).'"')
            ->action('View Document', route('documents.edit', $this->document->uuid));
    }
}
```

- [ ] **Step 4: Wire the email notifications into `dispatchNotifications()`**

In `app/Livewire/Documents/CommentThread.php`, add these imports alongside the existing notification imports:

```php
use App\Notifications\CommentPostedEmailNotification;
use App\Notifications\MentionedInCommentEmailNotification;
```

Replace `dispatchNotifications()` again (building on Task 2's version):

```php
    private function dispatchNotifications(Comment $comment, ?Comment $parent = null): void
    {
        $mentions = $comment->extractMentions();

        // Notify document owner (if not commenter)
        if ($this->document->owner_id !== Auth::id()) {
            $owner = $this->document->owner;
            $this->notifySafely($owner, new CommentPostedNotification($this->document, $comment));
            $this->notifySafely($owner, new CommentPostedEmailNotification($this->document, $comment));
        }

        // Notify parent comment author on reply
        if ($parent && $parent->user_id !== Auth::id()) {
            $this->notifySafely($parent->user, new CommentPostedNotification($this->document, $comment));
            $this->notifySafely($parent->user, new CommentPostedEmailNotification($this->document, $comment));
        }

        // Notify @mentioned users
        if (! empty($mentions)) {
            User::whereIn('name', $mentions)
                ->where('id', '!=', Auth::id())
                ->get()
                ->each(function (User $user) use ($comment) {
                    $this->notifySafely($user, new MentionedInCommentNotification($this->document, $comment));
                    $this->notifySafely($user, new MentionedInCommentEmailNotification($this->document, $comment));
                });
        }
    }
```

`notifySafely()` itself is unchanged from Task 2.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/CommentEmailNotificationsTest.php`
Expected: all five PASS.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests pass, including Task 2's `CommentNotificationsTest.php` (the email notifications are a separate dispatch and don't change the bell's own assertions).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/CommentPostedEmailNotification.php app/Notifications/MentionedInCommentEmailNotification.php app/Livewire/Documents/CommentThread.php tests/Feature/Documents/CommentEmailNotificationsTest.php
git commit -m "feat(notifications): add delayed, cancellable email for comments and mentions

CommentPostedEmailNotification and MentionedInCommentEmailNotification
are queued (unlike the now-synchronous bell notifications) and use
Laravel's native withDelay()/shouldSend() hooks: email holds for ~2
minutes, then only actually sends if the recipient hasn't already
read the matching bell notification. Dispatched alongside the
existing bell notifications from CommentThread::dispatchNotifications(),
through the same notifySafely() guard.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## After all tasks: manual production steps (not part of any task above)

Once this is reviewed and merged/deployed, two server-side actions are needed
— hand the user these exact commands, do not attempt them directly:

1. Set real mail config in production `.env`:
   ```bash
   cd /home/infodotc/doc.infodot.co.za/doc && sed -i.bak \
     -e 's|^MAIL_MAILER=.*|MAIL_MAILER=sendmail|' \
     -e 's|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=notifications@doc.infodot.co.za|' \
     .env && php artisan config:clear && php artisan config:cache
   ```
2. Add the cron entry that drives `schedule:run` (and therefore the new
   `queue:work` schedule entry) every minute, via cPanel's Cron Jobs UI or
   `crontab -e`:
   ```
   * * * * * php /home/infodotc/doc.infodot.co.za/doc/artisan schedule:run >> /dev/null 2>&1
   ```
