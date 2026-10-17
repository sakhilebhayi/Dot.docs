<?php

namespace App\Services\Guardian;

/**
 * One check's verdict in the dot-guardian/v1 vocabulary. `unknown` means
 * "no signal" and is never turned into a claim of health or failure.
 */
final class CheckResult
{
    public const HEALTHY = 'healthy';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    private const SEVERITY_ORDER = [
        self::HEALTHY => 0,
        self::UNKNOWN => 1,
        self::WARNING => 2,
        self::CRITICAL => 3,
    ];

    /** @param array<string, mixed> $metrics */
    private function __construct(
        private readonly string $status,
        private readonly string $message,
        private readonly array $metrics,
    ) {}

    /** @param array<string, mixed> $metrics */
    public static function healthy(string $message = '', array $metrics = []): self
    {
        return new self(self::HEALTHY, $message, $metrics);
    }

    /** @param array<string, mixed> $metrics */
    public static function warning(string $message = '', array $metrics = []): self
    {
        return new self(self::WARNING, $message, $metrics);
    }

    /** @param array<string, mixed> $metrics */
    public static function critical(string $message = '', array $metrics = []): self
    {
        return new self(self::CRITICAL, $message, $metrics);
    }

    /** @param array<string, mixed> $metrics */
    public static function unknown(string $message = '', array $metrics = []): self
    {
        return new self(self::UNKNOWN, $message, $metrics);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isWorseThan(self $other): bool
    {
        return self::SEVERITY_ORDER[$this->status] > self::SEVERITY_ORDER[$other->status];
    }

    /** @return array{status: string, message: string, metrics: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'metrics' => $this->metrics,
        ];
    }
}
