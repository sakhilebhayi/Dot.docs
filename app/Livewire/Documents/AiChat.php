<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Services\AiService;
use App\Support\SignedIn;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AiChat extends Component
{
    use AuthorizesRequests;
    use SignedIn;

    public Document $document;

    public string $message = '';

    /**
     * The conversation so far. Locked: the browser may read it but never
     * write it, so a page cannot stuff the transcript sent to the model
     * past the token budget the AI guard checks (EA-1 / S-14).
     *
     * @var array<mixed>
     */
    #[Locked]
    public array $history = [];

    public bool $loading = false;

    public bool $open = false;

    public function mount(Document $document): void
    {
        $this->document = $document;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function send(): void
    {
        $this->authorize('view', $this->document);

        $this->validate(['message' => 'nullable|string|max:4000']);

        $message = trim($this->message);
        if ($message === '') {
            return;
        }

        $ai = app(AiService::class);

        if (! $ai->checkRateLimit($this->actor()->id)) {
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'Rate limit reached. You can make 20 AI requests per hour.',
            ];
            $this->message = '';

            return;
        }

        $this->history[] = ['role' => 'user', 'content' => $message];
        $this->message = '';
        $this->loading = true;

        try {
            $html = $this->document->fresh()->content ?? '';

            // Pass all previous turns except the one just appended (it'll be re-added inside chat())
            $previousHistory = array_slice($this->history, 0, -1);

            $reply = $ai->chat($message, $html, $previousHistory);

            // With no model connected the demonstration provider answers with a placeholder such as "[mock:draft] …".
            // Say so in plain words instead of showing it as if it were an answer (R2-9; the result sheet already
            // labels its demonstration output the same way, EA-4 / F-03).
            if (config('ai.provider') === 'mock') {
                $reply = 'This is a demonstration: no AI model is connected to Dot.docs yet, so there is no real answer to "'
                    .Str::limit($message, 80).'". Your administrator can connect one.';
            }

            $this->history[] = ['role' => 'assistant', 'content' => $reply];
        } catch (\Throwable $e) {
            report($e);
            $this->history[] = [
                'role' => 'assistant',
                'content' => 'The assistant could not answer just now. Try again in a minute.',
            ];
        } finally {
            $this->loading = false;
        }

        // Keep last 20 turns to avoid token bloat
        if (count($this->history) > 20) {
            $this->history = array_slice($this->history, -20);
        }
    }

    public function clearHistory(): void
    {
        $this->history = [];
    }

    public function render(): View
    {
        return view('livewire.documents.ai-chat');
    }
}
