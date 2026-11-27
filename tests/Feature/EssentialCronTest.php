<?php

namespace Tests\Feature;

use App\Support\EssentialCron;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class EssentialCronTest extends TestCase
{
    public function test_every_named_email_job_is_still_scheduled(): void
    {
        $labels = collect(app(Schedule::class)->events())
            ->map(fn ($event) => strtolower(($event->command ?? '').' '.($event->description ?? '')));

        $this->assertIsArray(config('dot_cron.essential'));

        foreach ((array) config('dot_cron.essential') as $needle) {
            $this->assertTrue(
                $labels->contains(fn (string $label): bool => str_contains($label, strtolower((string) $needle))),
                "dot_cron.essential names '{$needle}' but no scheduled job matches it."
            );
        }
    }

    public function test_non_email_jobs_are_skipped_while_essential_only_is_on(): void
    {
        config(['dot_cron.essential_only' => true]);
        $schedule = app(Schedule::class);
        EssentialCron::apply($schedule);

        foreach ($schedule->events() as $event) {
            if (! EssentialCron::isEssential($event)) {
                $this->assertFalse($event->filtersPass($this->app), ($event->description ?: $event->command).' must be paused.');
            }
        }

        $this->assertNotEmpty(EssentialCron::needles());
    }

    public function test_the_status_command_reports_the_switch(): void
    {
        config(['dot_cron.essential_only' => false]);
        $this->artisan('dot:cron-status')->expectsOutputToContain('OFF (everything runs)')->assertSuccessful();

        config(['dot_cron.essential_only' => true]);
        $this->artisan('dot:cron-status')->expectsOutputToContain('ON (non-email jobs paused)')->assertSuccessful();
    }
}
