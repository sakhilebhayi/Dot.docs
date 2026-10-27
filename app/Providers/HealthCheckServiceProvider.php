<?php

namespace App\Providers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth before it
 * renders the page: a failing query here turns the answer into a 500 (the exception text is never shown).
 */
class HealthCheckServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));
    }
}
