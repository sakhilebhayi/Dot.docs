# Dot.Doc real-time notifications & smart email

## Context

The notification bell (`App\Livewire\NotificationBell`) already listens on a
private Echo/Reverb channel and live-updates the instant a notification is
created, for the two notification types that exist today:
`CommentPostedNotification` and `MentionedInCommentNotification`. Both
implement `ShouldQueue`, so `database` and `broadcast` dispatch through the
queue rather than synchronously.

A production diagnostic (2026-10-01) found that nothing async actually works
right now:

- `MAIL_MAILER=log` — the once-daily digest (`notifications:digest`,
  `App\Notifications\DailyDigestNotification`) has never sent a real email,
  only written to a log file.
- No queue worker process is running, and no `schedule:run` cron entry
  exists at all — Laravel's scheduler has never fired.
- 35 jobs were sitting unprocessed in the `jobs` table.
- `bootstrap.yml`'s plan for a persistent queue worker is a systemd service
  (`deploy/queue-worker.service`, `User=www-data`) — this shared cPanel
  account has no `sudo` binary at all (confirmed earlier fixing Reverb's
  reload step the same way), so that service can never actually be
  installed here. This is why `bootstrap.yml` has never been run for this
  repo and why the queue has never been drained.

Separately, SPF and DKIM are already correctly published for
`doc.infodot.co.za` via cPanel, so the server's own local mail (Exim) is a
reasonable, zero-signup outbound mail path for this app's size.

## Goals

1. Fix the underlying delivery infrastructure so queued jobs and scheduled
   commands actually run on this host, and so mail actually leaves the
   server.
2. Make the notification bell genuinely instant (not dependent on a queue
   tick), for the two notification types that exist today (comment posted,
   mentioned in a comment).
3. Add a smart, near-real-time email for the same two notification types:
   fires within about two minutes of the event, but only if the recipient
   hasn't already seen it (clicked it in the bell) by then.
4. Retire the once-daily digest, which becomes redundant once per-event
   email actually works.

Out of scope for this phase (explicitly deferred, not forgotten):

- New notification types beyond comment/mention (document shared, team
  invites, version restores, etc.).
- Suppressing email based on document presence (i.e. the recipient has the
  document open and would see the comment live in the thread itself, even
  without touching the bell). The only "seen" signal in this phase is the
  bell notification's own `read_at`.
- Diagnosing how Reverb is actually staying up in production today, given
  it has the identical systemd/sudo problem as the queue worker. Worth a
  separate look later; not blocking this work.

## Design

### 1. Delivery infrastructure

**Mail.** Production `.env`: `MAIL_MAILER=sendmail`,
`MAIL_FROM_ADDRESS=notifications@doc.infodot.co.za`,
`MAIL_FROM_NAME="Dot.Doc"`. No new account or credentials — `sendmail` shells
out to the server's own local MTA (Exim), which already has working
SPF/DKIM for this domain.

**Queue processing.** Shared hosting here has no daemon/supervisor access,
so the standard Laravel answer applies: run the queue in short cron-driven
bursts instead of a persistent worker. `routes/console.php` gets:

```php
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping(2);
```

One new cPanel cron entry drives Laravel's own scheduler, which is the only
thing that needs installing on the server for this phase:

```
* * * * * php /home/infodotc/doc.infodot.co.za/doc/artisan schedule:run >> /dev/null 2>&1
```

`withoutOverlapping(2)` prevents a slow-draining minute from starting a
second overlapping worker; the lock expires after 2 minutes rather than
the 24-hour default, so a process the host kills mid-run can't leave a
stuck lock that silently halts the queue for a day. `--stop-when-empty` means each invocation exits
as soon as the queue is drained rather than idling, so there's no risk of
two processes fighting over the same SQLite-backed `jobs` table for long.

The 35 already-queued jobs are pre-existing `SendQueuedNotifications` jobs
wrapping today's notification classes (`database` + `broadcast` only, no
`mail` channel) — they'll process cleanly on the first tick after this
deploys and will not trigger any email, since mail was never in their
channel list to begin with.

`deploy/queue-worker.service` and its `bootstrap.yml` installation step are
left in the repo but are now understood to be dead code for this specific
shared-hosting account (they'd still be correct for a VPS/root deployment of
this same app elsewhere). Not deleting them as part of this phase — that's
a separate cleanup decision, not a notifications change.

### 2. Real-time bell: stop queueing it

`CommentPostedNotification` and `MentionedInCommentNotification` drop
`ShouldQueue` entirely. `via()` stays `['database', 'broadcast']`. Both
channels now dispatch synchronously, inline, during the HTTP request that
creates the comment — a database insert and one HTTP call to Reverb, both
fast. This removes the queue from the bell's critical path entirely: it no
longer matters how often (or whether) a worker tick runs for the bell to
feel instant, because there's no queue hop in between.

Broadcasting failures (Reverb unreachable) must not break the comment-post
request itself — wrap the broadcast dispatch so a Reverb hiccup degrades to
"the bell updates late/not at all" rather than "posting a comment fails,"
matching how a live-collaboration app should already treat its websocket
layer as best-effort for anything that isn't the core editing operation.

### 3. Smart email: delayed, cancellable

A new notification per type —
`App\Notifications\CommentPostedEmailNotification` and
`App\Notifications\MentionedInCommentEmailNotification` — handles email
only. Both implement `ShouldQueue`. Both are dispatched alongside (not
instead of) the existing sync notification, from the same call site
(`CommentThread.php`).

Two native Laravel notification hooks (confirmed present in the installed
`laravel/framework` v13.32.0 via `NotificationSender`) do the actual work:

```php
public function withDelay(object $notifiable): array
{
    return ['mail' => now()->addMinutes(2)];
}

public function shouldSend(object $notifiable, string $channel): bool
{
    if ($channel !== 'mail') {
        return true;
    }

    return ! $notifiable->notifications()
        ->where('data->type', 'comment')
        ->where('data->comment_id', $this->comment->id)
        ->whereNotNull('read_at')
        ->exists();
}
```

The lookup is by comment id + type in the `data` JSON column, not by the
original notification's own id — Laravel's `NotificationSender` always
overwrites `$notification->id` with a freshly generated UUID at send time
(confirmed by reading the framework source), so there's no way to thread a
caller-chosen id through to tie the two dispatches together. Matching on
`(type, comment_id)` is simpler and sufficient: `read_at` being set on *any*
matching bell notification for this user means they've seen it.

Net behavior: comment posted → bell updates instantly. If the recipient
hasn't clicked it within about two minutes, an email goes out. If they
click it first, `shouldSend()` cancels the mail channel and no email is
ever sent.

### 4. Retire the daily digest

Delete `App\Notifications\DailyDigestNotification`,
`App\Console\Commands\SendDailyDigest`, and the
`Schedule::command('notifications:digest')->dailyAt('08:00')` entry in
`routes/console.php`. Delete their tests. Nothing replaces it — individual
near-real-time email is the replacement.

## Testing

- Feature test: posting a comment dispatches `database` + `broadcast`
  synchronously (no job pushed to the queue for those channels) and the
  recipient's `unreadNotifications()` count reflects it immediately.
- Feature test: the email notification's `shouldSend()` returns `false` when
  a matching bell notification is already read, and `true` when it isn't.
- Feature test: `withDelay()` returns a ~2 minute delay for the `mail`
  channel.
- Feature test: `routes/console.php`'s schedule includes the
  `queue:work --stop-when-empty` entry (`Schedule::command(...)` assertions
  via `Artisan::call('schedule:list')` or the `Schedule` facade's own test
  helpers, whichever this codebase already has precedent for — check
  existing scheduler tests, if any, before picking an approach).
- Remove `tests/Feature/...DailyDigest...` tests alongside the deleted code.
