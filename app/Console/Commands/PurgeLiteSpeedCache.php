<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class PurgeLiteSpeedCache extends Command
{
    protected $signature = 'cache:purge-litespeed';

    protected $description = 'Print a signed URL that purges LiteSpeed\'s LSCache when requested';

    public function handle(): int
    {
        $this->line(URL::temporarySignedRoute('ops.purge-cache', now()->addMinutes(5)));

        return self::SUCCESS;
    }
}
