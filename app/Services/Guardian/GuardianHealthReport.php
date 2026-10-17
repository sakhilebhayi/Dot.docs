<?php

namespace App\Services\Guardian;

use App\Services\Guardian\Contracts\GuardianCheck;

/**
 * The dot-guardian/v1 document: every check's verdict plus the worst one
 * as the overall status. A check that throws becomes `unknown`, never a
 * 500 -- the endpoint's own availability is a separate signal.
 */
final class GuardianHealthReport
{
    public const PLATFORM = 'dot-docs';

    /** @param list<GuardianCheck> $checks */
    public function __construct(private readonly array $checks) {}

    /** @return array{platform: string, contract: string, generated_at: string, status: string, checks: array<string, array{status: string, message: string, metrics: array<string, mixed>}>} */
    public function toArray(): array
    {
        $worst = CheckResult::healthy();
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $result = $check->run();
            } catch (\Throwable $e) {
                $result = CheckResult::unknown('Check failed to run: '.$e->getMessage());
            }

            if ($result->isWorseThan($worst)) {
                $worst = $result;
            }

            $results[$check->key()] = $result->toArray();
        }

        return [
            'platform' => self::PLATFORM,
            'contract' => 'dot-guardian/v1',
            'generated_at' => (string) now()->toISOString(),
            'status' => $worst->status(),
            'checks' => $results,
        ];
    }
}
