<?php

namespace App\Providers;

use App\Models\Document;
use App\Observers\DocumentObserver;
use App\Support\ShellContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolution of "which document is this page about" per request,
        // shared by the status line, the navigator rail and the dock.
        $this->app->scoped(ShellContext::class);
    }

    public function boot(): void
    {
        Document::observe(DocumentObserver::class);

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
    }
}
