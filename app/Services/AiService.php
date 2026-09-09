<?php

namespace App\Services;

use App\Models\AiSuggestion;
use App\Models\Document;
use Illuminate\Support\Facades\RateLimiter;
use OpenAI\Laravel\Facades\OpenAI;

class AiService
{
    private string $model;

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $this->model = config('openai.model', 'gpt-4o');
        $this->sanitizer = app(HtmlSanitizer::class);
    }

    /**
     * Check & decrement rate limit (20 requests per user per hour).
     */
    public function checkRateLimit(int $userId): bool
    {
        $key = "ai_rate:{$userId}";

        return RateLimiter::attempt($key, 20, fn () => true, 3600);
    }

    /**
     * Grammar & spell check — returns corrected HTML.
     */
    public function grammarCheck(string $html): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a grammar and spelling editor. Return only the corrected text, preserving all original formatting. Do not add commentary.'],
                ['role' => 'user',   'content' => $text],
            ],
            'max_tokens' => 2000,
        ]);

        return $response->choices[0]->message->content ?? $html;
    }

    /**
     * Summarize document content — returns a plain-text summary.
     */
    public function summarize(string $html, int $maxWords = 150): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => "Summarize the following document in {$maxWords} words or fewer. Be concise and capture the key points."],
                ['role' => 'user',   'content' => $text],
            ],
            'max_tokens' => 400,
        ]);

        return $response->choices[0]->message->content ?? '';
    }

    /**
     * Continue writing — generates the next paragraph based on the document.
     */
    public function continueWriting(string $html): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a writing assistant. Continue the following text naturally with one or two well-written paragraphs. Return only the new text, no preamble.'],
                ['role' => 'user',   'content' => $text],
            ],
            'max_tokens' => 500,
        ]);

        return '<p>'.nl2br(htmlspecialchars($response->choices[0]->message->content ?? '')).'</p>';
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

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => "Rewrite the following text in a {$tone} tone. Preserve the meaning. Return only the rewritten text."],
                ['role' => 'user',   'content' => $text],
            ],
            'max_tokens' => 2000,
        ]);

        return $response->choices[0]->message->content ?? $html;
    }

    /**
     * Translate content to the specified language.
     */
    public function translate(string $html, string $language = 'Spanish'): string
    {
        $text = $this->sanitizer->toPlainText($html);

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => "Translate the following text to {$language}. Return only the translated text."],
                ['role' => 'user',   'content' => $text],
            ],
            'max_tokens' => 3000,
        ]);

        return $response->choices[0]->message->content ?? $html;
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

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'Generate a structured document outline in HTML using <h2> for main sections and <h3> for sub-sections and <p> for brief descriptions. Return only the HTML.'],
                ['role' => 'user',   'content' => $text],
            ],
            'max_tokens' => 1000,
        ]);

        return $response->choices[0]->message->content ?? '';
    }

    /**
     * Free-form prompt with document context.
     */
    public function freePrompt(string $prompt, string $documentHtml): string
    {
        $text = $this->sanitizer->toPlainText($documentHtml);

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an AI writing assistant embedded in a document editor. The user will give you instructions about the document. Respond helpfully and concisely.'],
                ['role' => 'user',   'content' => "Document content:\n\n{$text}\n\nUser request: {$prompt}"],
            ],
            'max_tokens' => 1500,
        ]);

        return $response->choices[0]->message->content ?? '';
    }

    /**
     * Chat turn — maintains conversation about the document.
     * $history is array of ['role' => 'user'|'assistant', 'content' => '...']
     */
    public function chat(string $message, string $documentHtml, array $history = []): string
    {
        $docText = $this->sanitizer->toPlainText($documentHtml);
        $systemPrompt = "You are an AI assistant embedded in Dot.Doc, a document editor. The user is asking questions or requesting help about the following document:\n\n{$docText}\n\nBe helpful, accurate, and concise.";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $turn) {
            if (in_array($turn['role'] ?? '', ['user', 'assistant'])) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 800,
        ]);

        return $response->choices[0]->message->content ?? 'Sorry, I could not generate a response.';
    }

    /**
     * Execute a user-defined custom slash command.
     * Replaces {content} in the prompt template with the document plain text.
     */
    public function customCommand(string $promptTemplate, string $html): array
    {
        $text = $this->sanitizer->toPlainText($html);
        $prompt = str_replace('{content}', $text, $promptTemplate);

        $response = OpenAI::chat()->create([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an AI writing assistant embedded in a document editor. Follow the user\'s instruction precisely.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'max_tokens' => 2000,
        ]);

        return ['type' => 'replace', 'content' => $response->choices[0]->message->content ?? ''];
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
