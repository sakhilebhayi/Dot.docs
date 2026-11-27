<?php

namespace App\Livewire\Documents;

use App\Models\DocumentSlashCommand;
use App\Support\SignedIn;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SlashCommandManager extends Component
{
    use SignedIn;

    // Form fields
    public string $name = '';

    public string $description = '';

    public string $promptTemplate = '';

    public bool $shareWithTeam = false;

    public bool $showForm = false;

    public ?int $editingId = null;

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function editCommand(int $id): void
    {
        $cmd = $this->ownedCommand($id);
        if (! $cmd) {
            return;
        }

        $this->editingId = $id;
        $this->name = $cmd->name;
        $this->description = $cmd->description ?? '';
        $this->promptTemplate = $cmd->prompt_template;
        $this->shareWithTeam = $cmd->share_with_team;
        $this->showForm = true;
    }

    public function save(): void
    {
        // People type the command the way they will use it, "/summarise": the slash and case come off BEFORE the
        // name is checked (R2-10 - the rule refused the slash, so the clean-up below it never ran).
        $this->name = strtolower(ltrim(trim($this->name), '/'));

        $this->validate([
            'name' => 'required|alpha_dash|max:64',
            'description' => 'nullable|string|max:255',
            'promptTemplate' => 'required|string|max:2000',
        ]);

        $user = $this->actor();

        $data = [
            'user_id' => $user->id,
            'team_id' => $this->shareWithTeam ? $user->currentTeam?->id : null,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'prompt_template' => $this->promptTemplate,
            'share_with_team' => $this->shareWithTeam,
        ];

        if ($this->editingId) {
            $cmd = $this->ownedCommand($this->editingId);
            $cmd?->update($data);
        } else {
            DocumentSlashCommand::create($data);
        }

        $this->resetForm();
    }

    public function deleteCommand(int $id): void
    {
        $this->ownedCommand($id)?->delete();
    }

    private function ownedCommand(int $id): ?DocumentSlashCommand
    {
        return DocumentSlashCommand::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->promptTemplate = '';
        $this->shareWithTeam = false;
        $this->showForm = false;
    }

    public function render(): View
    {
        $user = $this->actor();
        $commands = DocumentSlashCommand::where('user_id', $user->id)
            ->orWhere(function ($q) use ($user) {
                if ($user->currentTeam) {
                    $q->where('team_id', $user->currentTeam->id)
                        ->where('share_with_team', true);
                }
            })
            ->orderBy('name')
            ->get();

        return view('livewire.documents.slash-command-manager', compact('commands'))
            ->layout('layouts.app')
            ->title('Slash commands');
    }
}
