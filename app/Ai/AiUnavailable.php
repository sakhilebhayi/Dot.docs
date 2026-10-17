<?php

namespace App\Ai;

use RuntimeException;

/**
 * A real model call was refused before it was made: the kill switch is off or
 * a daily token budget is used up. Callers already catch Throwable from the AI
 * client, so this degrades like any provider outage, without a provider call.
 */
class AiUnavailable extends RuntimeException
{
    public const SWITCHED_OFF = 'switched_off';

    public const GLOBAL_BUDGET = 'global_budget';

    public const USER_BUDGET = 'user_budget';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
