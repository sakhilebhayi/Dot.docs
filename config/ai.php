<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | 'mock' is the SAFE DEFAULT and the whole point of this key: an install
    | with no API key configured, and every test run, must never reach a real
    | model. Anything other than 'mock' hands the call to Prism, which routes
    | it by the model name (see App\Ai\Client::providerFor()).
    */

    'provider' => env('AI_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Model roles
    |--------------------------------------------------------------------------
    |
    | Three roles, one model each (platform spec section 6.2):
    |   draft   - rewrites, sections, summaries, reviews
    |   compose - whole-document generation, research synthesis
    |   quick   - grammar, translation, classification, slash suggestions
    */

    'models' => [
        'draft' => env('AI_MODEL_DRAFT', 'claude-sonnet-5'),
        'compose' => env('AI_MODEL_COMPOSE', 'claude-opus-5'),
        'quick' => env('AI_MODEL_QUICK', 'claude-haiku-4-5-20251001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failover chain
    |--------------------------------------------------------------------------
    |
    | Walked in order when the role's own model throws. Anthropic is the
    | primary provider, so the first fallback is a cheaper Anthropic model
    | (an overloaded Sonnet is usually still a working Haiku), and the last
    | is a different vendor so a whole-provider outage still has somewhere to
    | go. OpenAI is the second vendor because config/prism.php already ships
    | an `openai` block wired to OPENAI_API_KEY, which this app was using
    | before Prism replaced it - no new credential to provision.
    |
    | Each entry is [provider, model]. A leg with no credentials configured
    | simply throws and the walk moves on.
    */

    'failover' => [
        ['anthropic', env('AI_MODEL_QUICK', 'claude-haiku-4-5-20251001')],
        ['openai', env('AI_MODEL_FALLBACK', 'gpt-4o')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | ILLUSTRATIVE PLACEHOLDERS, in US dollars per million tokens. They exist
    | so `ai_model_usage.cost_usd` is populated and the accounting shape is
    | exercised end to end; they are NOT a price list and must be replaced
    | with the vendors' published rates before any figure from this table is
    | shown to a customer or billed. Round numbers, deliberately, so nobody
    | mistakes them for quoted precision. A model missing from this table
    | costs 0 - a missing price is never guessed.
    */

    'pricing_per_million' => [
        'claude-opus-5' => ['input' => 15.0, 'output' => 75.0],
        'claude-sonnet-5' => ['input' => 3.0, 'output' => 15.0],
        'claude-haiku-4-5-20251001' => ['input' => 1.0, 'output' => 5.0],
        'gpt-4o' => ['input' => 3.0, 'output' => 10.0],
        'mock' => ['input' => 0.0, 'output' => 0.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | AI requests per user per hour. Enforced by AiService::checkRateLimit().
    */

    'rate_limit_per_hour' => 20,

    /*
    |--------------------------------------------------------------------------
    | Default max tokens
    |--------------------------------------------------------------------------
    |
    | Overridable per call with $opts['max_tokens'].
    */

    'max_tokens' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Spend guard
    |--------------------------------------------------------------------------
    |
    | Limits that apply only to REAL model calls (never the mock provider), so a
    | leaked key, a runaway loop or one abusive account has a ceiling before the
    | provider's own limit. Tokens are counted from `ai_model_usage` for the
    | current UTC day. They are exact token counts, unlike `cost_usd`, which uses
    | the illustrative prices above. A refused call throws App\Ai\AiUnavailable,
    | which the AI screens already treat like any other provider failure.
    |
    |   enabled                      kill switch: AI_ENABLED=false stops every real call at once
    |   daily_token_budget           input + output tokens across all users per UTC day (0 = no cap)
    |   daily_token_budget_per_user  the same, per user (0 = no cap)
    |
    | The defaults are deliberately generous guesses (roughly a few dollars to a
    | few tens of dollars a day at current model prices), not measured usage.
    | Tune them from `ai_model_usage` once there is real traffic, and ALSO set a
    | hard monthly limit in each provider's own console: that is the one a
    | leaked key cannot bypass.
    */

    'enabled' => (bool) env('AI_ENABLED', true),

    'daily_token_budget' => (int) env('AI_DAILY_TOKEN_BUDGET', 2000000),

    'daily_token_budget_per_user' => (int) env('AI_DAILY_TOKEN_BUDGET_PER_USER', 300000),

];
