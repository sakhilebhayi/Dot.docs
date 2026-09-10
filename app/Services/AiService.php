<?php

namespace App\Services;

use App\Ai\Client;
use App\Models\AiSuggestion;
use App\Models\Document;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The editor's AI surface: the slash commands, the assistant panel and the
 * chat rail all come through here.
 *
 * Every method's TRANSPORT is App\Ai\Client - never a provider SDK. The
 * prompts, the sanitisation and the output shaping below are unchanged from
 * the OpenAI-era implementation; only the call underneath moved, so the
 * Livewire components calling these methods did not have to change at all.
 *
 * Roles follow the platform spec: `quick` for the cheap mechanical passes
 * (grammar, translation), `draft` for everything that writes prose.
 */
class AiService
{
    private HtmlSanitizer $sanitizer;

    public function __construct(private Client $client)
    {
        $this->sanitizer = app(HtmlSanitizer::class);
    }

    /**
     * Check & decrement rate limit (20 requests per user per hour).
     */
    public function checkRateLimit(int $userId): bool
    {
        $key = "ai_rate:{$userId}";

        return RateLimiter::attempt($key, config('ai.rate_limit_per_hour', 20), fn () => true, 3600);
    }

    /**
     * Grammar & spell check — returns corrected HTML.
     */
    public function grammarCheck(string $html): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $out = $this->client->text(
            'quick',
            'You are a grammar and spelling editor. Return only the corrected text, preserving all original formatting. Do not add commentary.',
            $text,
            ['operation' => 'grammar', 'max_tokens' => 2000],
        )->text;

        return $out !== '' ? $out : $html;
    }

    /**
     * Summarize document content — returns a plain-text summary.
     */
    public function summarize(string $html, int $maxWords = 150): string
    {
        $text = $this->sanitizer->toPlainText($html);

        return $this->client->text(
            'draft',
            "Summarize the following document in {$maxWords} words or fewer. Be concise and capture the key points.",
            $text,
            ['operation' => 'summarise', 'max_tokens' => 400],
        )->text;
    }

    /**
     * Continue writing — generates the next paragraph based on the document.
     */
    public function continueWriting(string $html): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $out = $this->client->text(
            'draft',
            'You are a writing assistant. Continue the following text naturally with one or two well-written paragraphs. Return only the new text, no preamble.',
            $text,
            ['operation' => 'continue', 'max_tokens' => 500],
        )->text;

        return '<p>'.nl2br(htmlspecialchars($out)).'</p>';
    }

    /**
     * Change tone — rewrites the text in the given tone.
     * Supported: formal, casual, persuasive, concise
     */
    public function changeTone(string $html, string $tone = 'formal'): string
    {
        $text = $this->sanitizer->toPlainText($html);
        $tones = ['formal', 'casual', 'persuasive', 'concise'];
        $tone = in_array($tone, $tones) ? $tone : 'formal';

        $out = $this->client->text(
            'draft',
            "Rewrite the following text in a {$tone} tone. Preserve the meaning. Return only the rewritten text.",
            $text,
            ['operation' => 'tone', 'max_tokens' => 2000],
        )->text;

        return $out !== '' ? $out : $html;
    }

    /**
     * Translate content to the specified language.
     */
    public function translate(string $html, string $language = 'Spanish'): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $out = $this->client->text(
            'quick',
            "Translate the following text to {$language}. Return only the translated text.",
            $text,
            ['operation' => 'translate', 'max_tokens' => 3000],
        )->text;

        return $out !== '' ? $out : $html;
    }

    /**
     * AI command — resolves a slash command against the document.
     * Supports: /summarize, /grammar, /continue, /tone [style], /translate [lang], /outline
     */
    public function runCommand(string $command, string $html): array
    {
        $command = trim($command);

        if (str_starts_with($command, '/summarize')) {
            return ['type' => 'replace', 'content' => $this->summarize($html)];
        }

        if (str_starts_with($command, '/grammar')) {
            return ['type' => 'replace', 'content' => $this->grammarCheck($html)];
        }

        if (str_starts_with($command, '/continue')) {
            return ['type' => 'append', 'content' => $this->continueWriting($html)];
        }

        if (str_starts_with($command, '/tone')) {
            $tone = trim(str_replace('/tone', '', $command)) ?: 'formal';

            return ['type' => 'replace', 'content' => $this->changeTone($html, $tone)];
        }

        if (str_starts_with($command, '/translate')) {
            $lang = trim(str_replace('/translate', '', $command)) ?: 'Spanish';

            return ['type' => 'replace', 'content' => $this->translate($html, $lang)];
        }

        if (str_starts_with($command, '/outline')) {
            return ['type' => 'replace', 'content' => $this->generateOutline($html)];
        }

        // Free-form prompt
        return ['type' => 'append', 'content' => $this->freePrompt($command, $html)];
    }

    /**
     * Generate a structured document outline.
     */
    public function generateOutline(string $promptOrHtml): string
    {
        $text = $this->sanitizer->toPlainText($promptOrHtml);

        return $this->client->text(
            'draft',
            'Generate a structured document outline in HTML using <h2> for main sections and <h3> for sub-sections and <p> for brief descriptions. Return only the HTML.',
            $text,
            ['operation' => 'outline', 'max_tokens' => 1000],
        )->text;
    }

    /**
     * Free-form prompt with document context.
     */
    public function freePrompt(string $prompt, string $documentHtml): string
    {
        $text = $this->sanitizer->toPlainText($documentHtml);

        return $this->client->text(
            'draft',
            'You are an AI writing assistant embedded in a document editor. The user will give you instructions about the document. Respond helpfully and concisely.',
            "Document content:\n\n{$text}\n\nUser request: {$prompt}",
            ['operation' => 'free_prompt', 'max_tokens' => 1500],
        )->text;
    }

    /**
     * Chat turn — maintains conversation about the document.
     * $history is array of ['role' => 'user'|'assistant', 'content' => '...']
     *
     * Client::text() takes one system block and one user block, so the prior
     * turns are folded into the user block as a labelled transcript rather
     * than sent as separate messages. The model sees the same conversation;
     * what it does not see is any turn whose role is not user/assistant,
     * which is the same filter the message-array version applied.
     */
    public function chat(string $message, string $documentHtml, array $history = []): string
    {
        $docText = $this->sanitizer->toPlainText($documentHtml);
        $systemPrompt = "You are an AI assistant embedded in Dot.Doc, a document editor. The user is asking questions or requesting help about the following document:\n\n{$docText}\n\nBe helpful, accurate, and concise.";

        $transcript = [];

        foreach ($history as $turn) {
            if (in_array($turn['role'] ?? '', ['user', 'assistant'])) {
                $label = $turn['role'] === 'user' ? 'User' : 'Assistant';
                $transcript[] = $label.': '.($turn['content'] ?? '');
            }
        }

        $transcript[] = 'User: '.$message;

        $out = $this->client->text(
            'draft',
            $systemPrompt,
            implode("\n\n", $transcript),
            ['operation' => 'chat', 'max_tokens' => 800],
        )->text;

        return $out !== '' ? $out : 'Sorry, I could not generate a response.';
    }

    /**
     * Execute a user-defined custom slash command.
     * Replaces {content} in the prompt template with the document plain text.
     */
    public function customCommand(string $promptTemplate, string $html): array
    {
        $text = $this->sanitizer->toPlainText($html);
        $prompt = str_replace('{content}', $text, $promptTemplate);

        $out = $this->client->text(
            'draft',
            'You are an AI writing assistant embedded in a document editor. Follow the user\'s instruction precisely.',
            $prompt,
            ['operation' => 'custom_command', 'max_tokens' => 2000],
        )->text;

        return ['type' => 'replace', 'content' => $out];
    }

    /**
     * Save a suggestion to the database.
     */
    public function saveSuggestion(Document $document, int $userId, string $text): AiSuggestion
    {
        return AiSuggestion::create([
            'document_id' => $document->id,
            'user_id' => $userId,
            'suggestion_text' => $text,
            'created_at' => now(),
        ]);
    }
}
