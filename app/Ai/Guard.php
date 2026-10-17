<?php

namespace App\Ai;

use App\Models\AiModelUsage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides, before any real model call, whether it may be made.
 *
 * Counts tokens recorded in `ai_model_usage` since 00:00 UTC, excluding the
 * mock provider and the placeholder rows written for calls that never reached
 * a model. See config/ai.php (Spend guard) for what each limit means.
 */
class Guard
{
    /** @param array{user_id?:int|null} $opts */
    public function assertAllowed(array $opts = []): void
    {
        if (config('ai.enabled', true) === false) {
            $this->refuse(AiUnavailable::SWITCHED_OFF, 'AI is switched off.');
        }

        $globalCap = (int) config('ai.daily_token_budget', 0);
        if ($globalCap > 0 && $this->tokensToday() >= $globalCap) {
            $this->refuse(AiUnavailable::GLOBAL_BUDGET, 'The daily AI budget has been used up. It resets at 00:00 UTC.');
        }

        $userCap = (int) config('ai.daily_token_budget_per_user', 0);
        $userId = $opts['user_id'] ?? Auth::id();
        if ($userCap > 0 && $userId !== null && $this->tokensToday((int) $userId) >= $userCap) {
            $this->refuse(AiUnavailable::USER_BUDGET, 'You have used your daily AI allowance. It resets at 00:00 UTC.');
        }
    }

    public function tokensToday(?int $userId = null): int
    {
        $query = AiModelUsage::query()
            ->where('created_at', '>=', now()->utc()->startOfDay())
            ->whereNotIn('provider', ['mock', 'none']);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return (int) $query->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) AS total')->value('total');
    }

    private function refuse(string $reason, string $message): never
    {
        // One warning per reason per hour, so a refused flood does not flood the log.
        if (Cache::add("ai-guard-warned:{$reason}", 1, 3600)) {
            Log::warning('AI call refused by the spend guard', ['reason' => $reason]);
        }

        throw new AiUnavailable($reason, $message);
    }
}
