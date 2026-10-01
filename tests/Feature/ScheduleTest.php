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
        $this->assertStringContainsString('--queue=mail,default', $queueEvent->command, 'Email must be drained before anything else.');
        $this->assertSame('* * * * *', $queueEvent->getExpression());
        $this->assertTrue($queueEvent->withoutOverlapping);
        $this->assertSame(2, $queueEvent->expiresAt, 'The overlap lock must expire in minutes, not the 24h default.');
    }

    public function test_failed_jobs_are_pruned(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'queue:prune-failed')));
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
