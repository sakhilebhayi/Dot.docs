<?php

namespace App\Ai;

use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Throwable;

/**
 * The one seam between this app and any language model.
 *
 * Nothing else in the codebase may talk to a provider SDK directly. Two
 * rules hold here and are covered by tests:
 *
 * 1. `config('ai.provider') === 'mock'` (the default, and forced for the
 *    whole test suite in phpunit.xml) returns a deterministic string and
 *    makes no network call at all.
 * 2. Every call - mock included - writes exactly one `ai_model_usage` row
 *    through App\Ai\Usage, so usage accounting can never drift from what
 *    was actually asked.
 *
 * Real calls go through Prism (prism-php/prism v0.100): a role picks the
 * model from config('ai.models'), the model prefix picks the provider, and
 * an exception walks config('ai.failover') in order before giving up.
 */
class Client
{
    public function __construct(private Usage $usage) {}

    /**
     * @param  'draft'|'compose'|'quick'  $role
     * @param  array{operation?:string,document_id?:int|null,user_id?:int|null,team_id?:int|null,max_tokens?:int}  $opts
     */
    public function text(string $role, string $system, string $user, array $opts = []): AiResult
    {
        $operation = (string) ($opts['operation'] ?? $role);

        if ($this->isMock()) {
            $result = $this->mockResult($role, $user);
            $this->usage->record($result, $operation, $opts['document_id'] ?? null, $opts['user_id'] ?? null, $opts['team_id'] ?? null);

            return $result;
        }

        [$result, $fallbackUsed] = $this->attemptChain(
            $this->chainFor($role),
            fn (string $provider, string $model): AiResult => $this->prismText($provider, $model, $system, $user, $opts),
        );

        $this->usage->record($result, $operation, $opts['document_id'] ?? null, $opts['user_id'] ?? null, $opts['team_id'] ?? null, $fallbackUsed);

        return $result;
    }

    /**
     * A model call whose answer is an object rather than prose.
     *
     * The seam exists now so Phase 2 operations (findings, extracted fields,
     * classifications) have somewhere to land; under the mock provider it
     * returns an empty array, which is the "no structure produced" answer
     * every caller has to handle anyway.
     *
     * @param  'draft'|'compose'|'quick'  $role
     * @param  array<string,mixed>  $jsonSchema  a raw JSON Schema object
     * @param  array{operation?:string,document_id?:int|null,user_id?:int|null,team_id?:int|null,max_tokens?:int}  $opts
     * @return array<string,mixed>
     */
    public function structured(string $role, string $system, string $user, array $jsonSchema, array $opts = []): array
    {
        $operation = (string) ($opts['operation'] ?? 'structured');

        if ($this->isMock()) {
            $result = $this->mockResult($role, $user);
            $this->usage->record($result, $operation, $opts['document_id'] ?? null, $opts['user_id'] ?? null, $opts['team_id'] ?? null);

            return [];
        }

        [$result, $fallbackUsed] = $this->attemptChain(
            $this->chainFor($role),
            fn (string $provider, string $model): AiResult => $this->prismStructured($provider, $model, $system, $user, $jsonSchema, $opts),
        );

        $this->usage->record($result, $operation, $opts['document_id'] ?? null, $opts['user_id'] ?? null, $opts['team_id'] ?? null, $fallbackUsed);

        return $result->structured ?? [];
    }

    /** The model this role resolves to right now. */
    public function modelFor(string $role): string
    {
        return (string) config('ai.models.'.$role, config('ai.models.draft'));
    }

    /**
     * Prism's provider key for a model name.
     *
     * Two deliberate narrowings of the plan's shorthand: Prism calls Google's
     * provider `gemini`, not `google`, so that is what a `gemini-` model maps
     * to; and the OpenAI reasoning family is matched as `o` + DIGIT (o1, o3,
     * o4-mini) rather than a bare `o`, which would have swallowed every
     * self-hosted Ollama model whose name happens to start with one.
     */
    public function providerFor(string $model): string
    {
        return match (true) {
            str_starts_with($model, 'claude-') => 'anthropic',
            str_starts_with($model, 'gpt-'), (bool) preg_match('/^o\d/', $model) => 'openai',
            str_starts_with($model, 'gemini-') => 'gemini',
            default => 'ollama',
        };
    }

    private function isMock(): bool
    {
        return config('ai.provider') === 'mock';
    }

    /**
     * Deterministic by contract: the same role and prompt always produce the
     * same string, which is what lets tests assert on AI-backed output.
     */
    private function mockResult(string $role, string $user): AiResult
    {
        return new AiResult(
            text: "[mock:{$role}] ".Str::limit($user, 60),
            inputTokens: 0,
            outputTokens: 0,
            model: 'mock',
            provider: 'mock',
            latencyMs: 0,
        );
    }

    /**
     * The role's own model first, then every configured failover leg. A leg
     * that repeats the model already tried is dropped - retrying the same
     * model against the same outage buys nothing.
     *
     * @return list<array{0:string,1:string}>
     */
    private function chainFor(string $role): array
    {
        $model = $this->modelFor($role);
        $chain = [[$this->providerFor($model), $model]];

        foreach ((array) config('ai.failover', []) as $leg) {
            if (! is_array($leg) || count($leg) < 2) {
                continue;
            }

            [$provider, $fallbackModel] = [(string) $leg[0], (string) $leg[1]];

            if ($fallbackModel === '' || $fallbackModel === $model) {
                continue;
            }

            $chain[] = [$provider, $fallbackModel];
        }

        return $chain;
    }

    /**
     * Walks the chain until one leg answers. If every leg throws, the LAST
     * exception is rethrown - the caller sees a real failure, never a
     * silently empty answer.
     *
     * @param  list<array{0:string,1:string}>  $chain
     * @param  callable(string,string):AiResult  $call
     * @return array{0:AiResult,1:bool}
     */
    private function attemptChain(array $chain, callable $call): array
    {
        $last = null;

        foreach ($chain as $index => [$provider, $model]) {
            try {
                return [$call($provider, $model), $index > 0];
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        throw $last ?? new \RuntimeException('No AI provider configured.');
    }

    /** @param array{max_tokens?:int} $opts */
    private function prismText(string $provider, string $model, string $system, string $user, array $opts): AiResult
    {
        $started = hrtime(true);

        $response = Prism::text()
            ->using($provider, $model)
            ->withSystemPrompt($system)
            ->withPrompt($user)
            ->withMaxTokens((int) ($opts['max_tokens'] ?? config('ai.max_tokens', 2000)))
            ->asText();

        return new AiResult(
            text: $response->text,
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            model: $response->meta->model ?: $model,
            provider: $provider,
            latencyMs: $this->elapsedMs($started),
            cacheReadTokens: $response->usage->cacheReadInputTokens ?? 0,
        );
    }

    /**
     * @param  array<string,mixed>  $jsonSchema
     * @param  array{max_tokens?:int}  $opts
     */
    private function prismStructured(string $provider, string $model, string $system, string $user, array $jsonSchema, array $opts): AiResult
    {
        $started = hrtime(true);

        $response = Prism::structured()
            ->using($provider, $model)
            ->withSchema(new RawSchema('response', $jsonSchema))
            ->withSystemPrompt($system)
            ->withPrompt($user)
            ->withMaxTokens((int) ($opts['max_tokens'] ?? config('ai.max_tokens', 2000)))
            ->asStructured();

        return new AiResult(
            text: $response->text,
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            model: $response->meta->model ?: $model,
            provider: $provider,
            latencyMs: $this->elapsedMs($started),
            cacheReadTokens: $response->usage->cacheReadInputTokens ?? 0,
            structured: $response->structured ?? [],
        );
    }

    private function elapsedMs(float|int $startedAtHrtime): int
    {
        return (int) round((hrtime(true) - $startedAtHrtime) / 1_000_000);
    }
}
