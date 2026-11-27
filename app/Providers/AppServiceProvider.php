<?php

namespace App\Providers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Mail\Delivery\MailDeliveryListener;
use App\Models\Document;
use App\Models\Files\File;
use App\Models\Files\Folder;
use App\Models\Files\Obj;
use App\Observers\DocumentObserver;
use App\Observers\InfoDotResourceObserver;
use App\Policies\ObjPolicy;
use App\Search\FullTextIndex;
use App\Services\SyncMetrics;
use App\Support\EssentialCron;
use App\Support\ShellContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolution of "which document is this page about" per request,
        // shared by the status line, the navigator rail and the dock.
        $this->app->scoped(ShellContext::class);
        // Whether the full-text index is usable is looked up once per request (R2-5 / P-07).
        $this->app->scoped(FullTextIndex::class);
    }

    public function boot(): void
    {
        EssentialCron::listen();

        // New passwords: at least 12 characters; in production also refused
        // when they appear in a known breach (Pwned Passwords range lookup -
        // only the first five characters of the SHA-1 hash leave the server).
        // The same rule InfoDot adopted in R-72 (EA-1 / S-08).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12));

        // R-50: Livewire updates from a page in the signed-in area carry that page's entitlement check with them.
        Livewire::addPersistentMiddleware([EnsureCompanyEntitled::class]);

        // R2-5 / P-07: GET /up checks that this (web) PHP can read the full-text index, and stands it down if not.
        Event::listen(DiagnosingHealth::class, fn () => app(FullTextIndex::class)->guard());

        // R2-4 / E-10: each queued email is sent once, and recorded when it goes (App\Mail\Delivery).
        Event::listen(NotificationSending::class, [MailDeliveryListener::class, 'notificationSending']);
        Event::listen(NotificationSent::class, [MailDeliveryListener::class, 'notificationSent']);
        Event::listen(MessageSending::class, [MailDeliveryListener::class, 'messageSending']);
        Event::listen(MessageSent::class, [MailDeliveryListener::class, 'messageSent']);

        Document::observe(DocumentObserver::class);
        Document::observe(InfoDotResourceObserver::class);

        // The shared Dot.Files tree stores its polymorphic type as the
        // literal strings `file` / `folder` / `document` - the aliases
        // Dot.Files itself writes - so neither product's namespaces leak
        // into rows the other one reads. See .ai/rules/files.md.
        Relation::morphMap([
            'file' => File::class,
            'folder' => Folder::class,
            'document' => Document::class,
        ]);

        // Registered rather than discovered: App\Models\Files\Obj sits one
        // namespace deeper than the convention Laravel guesses from.
        Gate::policy(Obj::class, ObjPolicy::class);

        // Guards the password-unlock POST on both public read surfaces
        // (/shared/{uuid} and /d/{slug} — see .ai/rules/publishing.md).
        // Keyed by ip()+the address being tried, not by ip() alone: a slug
        // is human-chosen and guessable (unlike a uuid), so this closes the
        // brute-force surface on ONE link without locking a reader out of
        // every other shared document behind the same IP.
        RateLimiter::for('published-unlock', function (Request $request) {
            $target = $request->route('slug') ?? $request->route('uuid') ?? 'unknown';

            return Limit::perMinute(10)->by($request->ip().'|'.$target);
        });

        // The editor's counter flush (POST /documents/{uuid}/sync-metrics):
        // about 5 s after a page opens, then once a minute, and on leaving,
        // so SyncMetrics::PER_MINUTE is far more than a real tab sends.
        // Per person AND document, never per IP: colleagues behind one
        // office address look like one IP.
        // The token API (routes/api.php): 60 calls a minute per person (R2-1 / S-07).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for('sync-metrics', function (Request $request) {
            return Limit::perMinute(SyncMetrics::PER_MINUTE)->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->route('uuid'));
        });
    }
}
