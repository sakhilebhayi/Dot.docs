<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentSlashCommand;
use App\Services\AiService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class AiAssistant extends Component
{
    use AuthorizesRequests;

    public Document $document;

    /** Current AI action being processed */
    public string $action = '';

    /** Extra parameter (tone style, language, prompt text) */
    public string $param = '';

    /** Result text shown in preview panel */
    public string $result = '';

    /** Whether the result panel is visible */
    public bool $showResult = false;

    /** Whether an AI request is in-flight */
    public bool $loading = false;

    /** Command palette input */
    public string $command = '';

    public bool $showPalette = false;

    public array $commandSuggestions = [
        '/summarize' => 'Generate a TL;DR summary',
        '/grammar' => 'Fix grammar & spelling',
        '/continue' => 'Continue writing from here',
        '/tone formal' => 'Rewrite in formal tone',
        '/tone casual' => 'Rewrite in casual tone',
        '/tone persuasive' => 'Rewrite in persuasive tone',
        '/tone concise' => 'Rewrite in concise tone',
        '/translate' => 'Translate (e.g. /translate French)',
        '/outline' => 'Generate document outline',
    ];

    public function mount(Document $document): void
    {
        $this->document = $document;
    }

    /** Triggered by editor toolbar quick-action buttons */
    #[On('ai-action')]
    public function handleAction(string $action, string $param = ''): void
    {
        $this->action = $action;
        $this->param = $param;
        $this->runAi();
    }

    #[On('open-palette')]
    public function openPalette(): void
    {
        $this->showPalette = true;
        $this->command = '';
        $this->result = '';
        $this->showResult = false;

        // Merge custom user/team commands into the suggestion list
        $user = Auth::user();
        $customs = DocumentSlashCommand::where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->currentTeam) {
                $q->orWhere(function ($q2) use ($user) {
                    $q2->where('team_id', $user->currentTeam->id)
                        ->where('share_with_team', true);
                });
            }
        })
            ->get()
            ->mapWithKeys(fn ($cmd) => ["/{$cmd->name}" => $cmd->description ?? 'Custom command']);

        $this->commandSuggestions = array_merge($this->commandSuggestions, $customs->all());
    }

    public function closePalette(): void
    {
        $this->showPalette = false;
    }

    public function runCommand(): void
    {
        $this->action = 'command';
        $this->runAi();
    }

    private function runAi(): void
    {
        $this->authorize('view', $this->document);

        $ai = app(AiService::class);

        if (! $ai->checkRateLimit(Auth::id())) {
            $this->result = 'Rate limit reached. You can make 20 AI requests per hour.';
            $this->showResult = true;
            $this->showPalette = false;

            return;
        }

        $this->loading = true;
        $html = $this->document->fresh()->content ?? '';

        try {
            $output = match ($this->action) {
                'summarize' => $ai->summarize($html),
                'grammar' => $ai->grammarCheck($html),
                'continue' => $ai->continueWriting($html),
                'tone' => $ai->changeTone($html, $this->param ?: 'formal'),
                'translate' => $ai->translate($html, $this->param ?: 'Spanish'),
                'outline' => $ai->generateOutline($html),
                'command' => $this->resolveCommand($ai, $html),
                default => $ai->freePrompt($this->action, $html),
            };

            // Save to ai_suggestions
            $ai->saveSuggestion($this->document, Auth::id(), is_array($output) ? ($output['content'] ?? '') : $output);

            $this->result = is_array($output) ? ($output['content'] ?? '') : $output;
            $this->showResult = true;
            $this->showPalette = false;

            // Tell the editor to apply if it's a direct replace/append
            if (is_array($output)) {
                $this->dispatch('ai-result', type: $output['type'], content: $output['content']);
            }
        } catch (\Throwable $e) {
            $this->result = 'AI request failed: '.$e->getMessage();
            $this->showResult = true;
        } finally {
            $this->loading = false;
        }
    }

    private function resolveCommand(AiService $ai, string $html): array
    {
        $command = trim($this->command);

        // Check if it matches a custom slash command for the current user
        $user = Auth::user();
        $cmdName = ltrim(explode(' ', $command)[0], '/');

        $custom = DocumentSlashCommand::where('name', $cmdName)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if ($user->currentTeam) {
                    $q->orWhere(function ($q2) use ($user) {
                        $q2->where('team_id', $user->currentTeam->id)
                            ->where('share_with_team', true);
                    });
                }
            })
            ->first();

        if ($custom) {
            return $ai->customCommand($custom->prompt_template, $html);
        }

        return $ai->runCommand($command, $html);
    }

    public function applyResult(): void
    {
        // Accepting a result writes into the document, so it needs the same
        // right as typing does. Reading what the assistant says does not.
        $this->authorize('update', $this->document);

        $this->dispatch('ai-apply', content: $this->result);
        $this->showResult = false;
    }

    public function dismissResult(): void
    {
        $this->showResult = false;
        $this->result = '';
    }

    public function render()
    {
        return view('livewire.documents.ai-assistant');
    }
}
