<?php

namespace App\Livewire\Documents;

use App\Models\DocumentSlashCommand;
use Livewire\Component;

class SlashCommandManager extends Component
{
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
        $this->validate([
            'name' => 'required|alpha_dash|max:64',
            'description' => 'nullable|string|max:255',
            'promptTemplate' => 'required|string|max:2000',
        ]);

        $user = auth()->user();

        $data = [
            'user_id' => $user->id,
            'team_id' => $this->shareWithTeam ? $user->currentTeam?->id : null,
            'name' => strtolower(ltrim($this->name, '/')),
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

    public function render()
    {
        $user = auth()->user();
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
