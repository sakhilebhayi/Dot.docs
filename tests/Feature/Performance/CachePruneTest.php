<?php

namespace Tests\Feature\Performance;

use App\Support\EssentialCron;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R2-11: expired rows of the database cache are deleted daily instead of staying until their key is read again. */
class CachePruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_cache_and_lock_rows_go_and_live_ones_stay(): void
    {
        config(['cache.default' => 'database']);
        $now = now()->getTimestamp();

        $old = [];
        for ($i = 0; $i < 600; $i++) {
            $old[] = ['key' => "old-{$i}", 'value' => 'x', 'expiration' => $now - 60];
        }
        DB::table('cache')->insert($old);
        DB::table('cache')->insert(['key' => 'live', 'value' => 'x', 'expiration' => $now + 3600]);
        DB::table('cache_locks')->insert([
            ['key' => 'old-lock', 'owner' => 'a', 'expiration' => $now - 60],
            ['key' => 'live-lock', 'owner' => 'b', 'expiration' => $now + 3600],
        ]);

        $this->artisan('cache:prune-expired')->expectsOutput('Deleted 601 expired cache rows.')->assertSuccessful();

        $this->assertSame(['live'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['live-lock'], DB::table('cache_locks')->pluck('key')->all());
    }

    public function test_another_cache_store_is_left_alone(): void
    {
        config(['cache.default' => 'array']);

        $this->artisan('cache:prune-expired')
            ->expectsOutput('The cache store is not the database: nothing to prune.')
            ->assertSuccessful();
    }

    public function test_it_is_scheduled_daily_and_is_not_essential_work(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'cache:prune-expired'));

        $this->assertNotNull($event);
        $this->assertSame('55 3 * * *', $event->getExpression());
        $this->assertFalse(EssentialCron::isEssential($event));
    }
}
