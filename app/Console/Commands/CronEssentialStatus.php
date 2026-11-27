<?php

namespace App\Console\Commands;

use App\Support\EssentialCron;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

class CronEssentialStatus extends Command
{
    protected $signature = 'dot:cron-status';

    protected $description = 'List which scheduled jobs run and which are paused while only email and notification work is allowed';

    public function handle(Schedule $schedule): int
    {
        $this->line('essential only: '.(config('dot_cron.essential_only') ? 'ON (non-email jobs paused)' : 'OFF (everything runs)'));

        foreach ($schedule->events() as $event) {
            $runs = ! config('dot_cron.essential_only') || EssentialCron::isEssential($event);
            $this->line(($runs ? 'RUNS    ' : 'PAUSED  ').str_pad($event->expression, 16).' '.($event->description ?: $event->command));
        }

        return self::SUCCESS;
    }
}
