<?php

namespace App\Providers;

use App\Models\Document;
use App\Observers\DocumentObserver;
use App\Support\ShellContext;
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
    }
}
