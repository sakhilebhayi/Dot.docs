<?php

namespace App\Ai;

/**
 * One completed model call. Everything App\Ai\Usage needs to write an
 * `ai_model_usage` row, plus the text the caller asked for.
 */
readonly class AiResult
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public string $provider,
        public int $latencyMs,
        public int $cacheReadTokens = 0,
        /** @var array<string,mixed>|null Parsed object from a structured() call */
        public ?array $structured = null,
    ) {}
}
