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

];
