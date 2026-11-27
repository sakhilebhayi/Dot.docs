<?php

use App\Http\Middleware\RefuseApiTokensOnPages;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Guardian\ErrorCounter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The shell's night/day switch is written by resources/js/shell.js and
        // read back server-side in layouts/app.blade.php so a day-mode reload
        // never flashes night. An encrypted cookie would come back null there.
        $middleware->encryptCookies(except: ['theme']);

        // API tokens never reach a page or a Livewire action (R2-1 / S-07): on the whole web group, so
        // /livewire/update is covered too.
        $middleware->web(append: [RefuseApiTokensOnPages::class]);

        // Sanctum's token-permission checks for routes/api.php (R2-1 / S-07).
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Last in the global stack so it sees every response, including 404s and error pages.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Feeds the guardian's hourly error counter (ErrorRateCheck). The
        // counter swallows its own failures, so this cannot break reporting.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorCounter::class)->record($e);
        });
    })->create();
