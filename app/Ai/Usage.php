<?php

namespace App\Ai;

use App\Models\AiModelUsage;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one `ai_model_usage` row per model call.
 *
 * Every call through App\Ai\Client lands here - mock runs included - so the
 * accounting table is never a partial picture of what the app asked a model
 * to do. Cost comes from config('ai.pricing_per_million'), which is a table
 * of ILLUSTRATIVE placeholder rates (see that file); a model missing from it
 * costs 0 rather than a guessed number.
 */
class Usage
{
    public function record(
        AiResult $result,
        string $operation,
        ?int $documentId = null,
        ?int $userId = null,
        ?int $teamId = null,
        bool $fallbackUsed = false,
    ): AiModelUsage {
        $user = Auth::user();
        $userId ??= $user?->id;
        $teamId ??= $user?->currentTeam?->id;

        return AiModelUsage::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'document_id' => $documentId,
            'provider' => $result->provider,
            'model' => $result->model,
            'operation' => $operation,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_read_tokens' => $result->cacheReadTokens,
            'cost_usd' => $this->cost($result),
            'latency_ms' => $result->latencyMs,
            'fallback_used' => $fallbackUsed,
            'created_at' => now(),
        ]);
    }

    /** Dollars for this call, rounded to the column's six decimal places. */
    public function cost(AiResult $result): float
    {
        // Indexed, not dot-notated: a model name is vendor-chosen and may
        // carry a `.` (e.g. gpt-4.1), which config() would read as a level.
        $table = config('ai.pricing_per_million', []);
        $rates = is_array($table) ? ($table[$result->model] ?? null) : null;

        if (! is_array($rates)) {
            return 0.0;
        }

        $input = ($result->inputTokens + $result->cacheReadTokens) / 1_000_000 * (float) ($rates['input'] ?? 0);
        $output = $result->outputTokens / 1_000_000 * (float) ($rates['output'] ?? 0);

        return round($input + $output, 6);
    }
}
