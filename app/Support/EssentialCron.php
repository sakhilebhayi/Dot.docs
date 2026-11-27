<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event as Events;

/**
 * Owner order 2026-11-27: while the Dot ecosystem is still being built, the shared host runs only the scheduled
 * work that sends email and notifications, plus the queue workers that deliver queued mail and the health
 * heartbeats the watchdogs read. Everything else, nightly backups included, is skipped while
 * config('dot_cron.essential_only') is true. To restart everything set DOT_CRON_ESSENTIAL_ONLY=false in .env
 * (then `php artisan config:cache`), or flip the default in config/dot_cron.php. `php artisan dot:cron-status`
 * lists what runs and what is paused.
 */
final class EssentialCron
{
    public static function listen(): void
    {
        Events::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (in_array($event->command, ['schedule:run', 'schedule:work'], true) && config('dot_cron.essential_only')) {
                self::apply(app(Schedule::class));
            }
        });
    }

    public static function apply(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            if (! self::isEssential($event)) {
                $event->skip(static fn (): bool => (bool) config('dot_cron.essential_only'));
            }
        }
    }

    public static function isEssential(Event $event): bool
    {
        $label = strtolower(trim(($event->command ?? '').' '.($event->description ?? '')));

        foreach (self::needles() as $needle) {
            if (str_contains($label, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function needles(): array
    {
        $needles = [];
        foreach (array_merge((array) config('dot_cron.always', []), (array) config('dot_cron.essential', [])) as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '') {
                $needles[] = $needle;
            }
        }

        return $needles;
    }
}
