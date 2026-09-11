<?php

namespace Tests\Feature\Documents;

use App\Files\FilesService;
use App\Livewire\Documents\LocationPicker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocationPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_the_current_folder_and_resolves_to_its_obj(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $root = app(FilesService::class)->root($team);
        $reports = app(FilesService::class)->createFolder($root, 'Reports', $user);

        $component = Livewire::actingAs($user)->test(LocationPicker::class, ['currentFolderId' => $reports->id]);
        $component->assertSet('selectedId', $reports->id);

        $this->assertSame($reports->id, $component->instance()->resolve()->id);
    }

    public function test_switching_folders_updates_the_resolved_location(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $root = app(FilesService::class)->root($team);
        $drafts = app(FilesService::class)->createFolder($root, 'Drafts', $user);

        $component = Livewire::actingAs($user)->test(LocationPicker::class, ['currentFolderId' => $root->id]);
        $component->call('selectFolder', $drafts->id);

        $this->assertSame($drafts->id, $component->instance()->resolve()->id);
    }

    public function test_a_folder_outside_the_users_team_cannot_be_selected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $strangersRoot = app(FilesService::class)->root($stranger->currentTeam);

        Livewire::actingAs($user)->test(LocationPicker::class, ['currentFolderId' => null])
            ->call('selectFolder', $strangersRoot->id)
            ->assertForbidden();
    }
}
