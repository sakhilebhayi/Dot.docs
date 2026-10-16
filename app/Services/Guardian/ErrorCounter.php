<?php

namespace App\Services\Guardian;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed hourly counter of reported exceptions, fed from the
 * exception handler's report hook in bootstrap/app.php and read by
 * ErrorRateCheck. Nothing here ever throws: guardian accounting must not
 * be able to break real error handling.
 */
class ErrorCounter
{
    private const KEY_PREFIX = 'guardian:errors:';

    public const LAST_ERROR_KEY = 'guardian:errors:last';

    public function record(\Throwable $e): void
    {
        try {
            $key = self::KEY_PREFIX.now()->format('Y-m-d-H');

            Cache::add($key, 0, 7200);
            Cache::increment($key);

            Cache::put(self::LAST_ERROR_KEY, [
                'class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 500),
                'at' => now()->toISOString(),
            ], 86400);
        } catch (\Throwable) {
            // Counting must never interfere with real error handling.
        }
    }

    public function countForHour(\DateTimeInterface $hour): int
    {
        $value = Cache::get(self::KEY_PREFIX.$hour->format('Y-m-d-H'));

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array{class: string, message: string, at: string}|null */
    public function lastError(): ?array
    {
        $stored = Cache::get(self::LAST_ERROR_KEY);

        if (! is_array($stored) || ! is_string($stored['class'] ?? null) || ! is_string($stored['at'] ?? null)) {
            return null;
        }

        return [
            'class' => $stored['class'],
            'message' => is_string($stored['message'] ?? null) ? $stored['message'] : '',
            'at' => $stored['at'],
        ];
    }
}
