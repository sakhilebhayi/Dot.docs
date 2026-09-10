---
paths:
  - 'app/Ai/**'
---

# Ai

## The AI client is mock by default and every call is accounted for
App\Ai\Client is the ONLY seam to a language model - nothing else may call a provider SDK, and prism-php/prism v0.100 is the transport (Prism::text()->using($provider,$model)->withSystemPrompt()->withPrompt()->withMaxTokens()->asText(); structured() uses Prism::structured()->withSchema(new RawSchema(...))->asStructured()).
config("ai.provider") defaults to "mock" and phpunit.xml forces AI_PROVIDER=mock for the whole suite, so no test can reach a real provider. Mock returns "[mock:{role}] " . Str::limit($user, 60) with zero tokens - deterministic by contract, which is what lets tests assert on AI-backed output.
Every call - mock included - writes exactly ONE ai_model_usage row through App\Ai\Usage::record(), so usage accounting can never drift from what was actually asked. cost_usd comes from config("ai.pricing_per_million"), whose numbers are ILLUSTRATIVE PLACEHOLDERS: replace them with published vendor rates before any figure is shown to a customer or billed. A model missing from that table costs 0 - a missing price is never guessed.
A role (draft|compose|quick) picks the model; the model prefix picks the Prism provider (claude- anthropic, gpt- or o+digit openai, gemini- gemini, which is Prism's key for Google, else ollama). Any exception walks config("ai.failover") in order and the LAST exception is rethrown if every leg fails.
App\Models\AiModelUsage must keep its explicit $table = "ai_model_usage"; Eloquent's pluraliser looks for ai_model_usages.
