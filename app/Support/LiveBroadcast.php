<?php

namespace App\Support;

/**
 * Whether a broadcast can reach anyone. With the `null` or `log` broadcaster (production today: no socket server, see
 * .ai/rules/notifications.md) a broadcast reaches nobody, so the events ask this from broadcastWhen() and Laravel then
 * skips building and handing over the payload on every save. Listeners in the app still run.
 */
final class LiveBroadcast
{
    public static function available(): bool
    {
        $connection = config('broadcasting.default');
        $driver = is_string($connection) ? config('broadcasting.connections.'.$connection.'.driver') : null;

        return is_string($driver) && ! in_array($driver, ['null', 'log'], true);
    }
}
