<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentSlashCommand;
use App\Services\AiService;
use App\Services\HtmlSanitizer;
use App\Support\SignedIn;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class AiAssistant extends Component
{
    use AuthorizesRequests;
    use SignedIn;

    public Document $document;

    /** Current AI action being processed */
    public string $action = '';

    /** Extra parameter (tone style, language, prompt text) */
    public string $param = '';

    /** Result text shown in preview panel */
    #[Locked]
    public string $result = '';

    /** Whether the result panel is visible */
    public bool $showResult = false;

    /**
     * How an accepted result goes into the document: 'replace' (grammar,
     * tone, translate: a rewrite of the whole text), 'insert' at the cursor
     * (continue, summary, outline, free prompts), or null when there is
     * nothing to accept (a refusal or an error). Locked: the browser must
     * not turn an insert into a replace (EA-4 / F-02).
     */
    #[Locked]
    public ?string $applyMode = null;

    /** Whether an AI request is in-flight */
    public bool $loading = false;

    /** Command palette input */
    public string $command = '';

    public bool $showPalette = false;

    /** @var array<mixed> */
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
        $user = $this->actor();
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

        if (! $ai->checkRateLimit($this->actor()->id)) {
            $this->result = 'Rate limit reached. You can make 20 AI requests per hour.';
            $this->applyMode = null;
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

            // Not stored as a pending suggestion any more (EA-4 / F-04): every
            // run used to land in "Waiting for you", dropped and accepted ones
            // included. The person decides here, in this sheet.
            $this->result = is_array($output) ? ($output['content'] ?? '') : $output;
            $this->applyMode = $this->modeFor($output);
            $this->showResult = true;
            $this->showPalette = false;
        } catch (\Throwable $e) {
            // The exception text is for the log, not the page: it can carry
            // provider details, and the result sheet prints HTML (EA-1 / S-04).
            report($e);
            $this->result = 'The assistant could not answer just now. Try again in a minute.';
            $this->applyMode = null;
            $this->showResult = true;
        } finally {
            $this->loading = false;
        }
    }

    /** @return array<mixed> */
    private function resolveCommand(AiService $ai, string $html): array
    {
        $command = trim($this->command);

        // Check if it matches a custom slash command for the current user
        $user = $this->actor();
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

        // The cleaned answer, never the raw property the browser could have rewritten.
        if ($this->applyMode === null) {
            return;
        }

        $this->dispatch('ai-apply', mode: $this->applyMode, content: $this->safeResult());
        $this->showResult = false;
    }

    /**
     * The result as it may be printed: model output is steered by the
     * document's own text (a teammate's, or a pasted page), so it goes through
     * the same allowlist as every other document HTML before it reaches the
     * page. `$result` is a public property the browser could also change, so
     * the cleaning happens here, at render time, every time (EA-1 / S-04).
     */
    public function safeResult(): string
    {
        return app(HtmlSanitizer::class)->clean($this->result);
    }

    /**
     * Only a rewrite of the whole text replaces it: grammar, tone, translate,
     * whether chosen from the toolbar or typed in the palette. Everything else
     * - summaries, outlines, continuations, free prompts, custom slash
     * commands - is added, never put in place of the writer's text. Decided
     * from the command, not from the service's own `type`, which still says
     * 'replace' for /summarize, /outline and every custom command.
     *
     * @param  string|array{type?: string, content?: string}  $output
     */
    private function modeFor(string|array $output): string
    {
        $name = $this->action === 'command'
            ? ltrim((string) strtok(trim($this->command), ' '), '/')
            : $this->action;

        return in_array($name, ['grammar', 'tone', 'translate'], true) ? 'replace' : 'insert';
    }

    /** True while the mock provider answers: no model is connected (EA-4 / F-03). */
    public function isDemonstration(): bool
    {
        return config('ai.provider') === 'mock';
    }

    public function dismissResult(): void
    {
        $this->showResult = false;
        $this->result = '';
        $this->applyMode = null;
    }

    public function render(): View
    {
        return view('livewire.documents.ai-assistant');
    }
}
