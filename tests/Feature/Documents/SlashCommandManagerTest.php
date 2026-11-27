<?php

namespace Tests\Feature\Documents;

use App\Livewire\Documents\SlashCommandManager;
use App\Models\DocumentSlashCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-10: the slash-command settings page - make, share, edit and delete a command, and only your own. */
class SlashCommandManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_command_is_made_with_a_clean_name_and_can_be_edited_and_deleted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)->test(SlashCommandManager::class)
            ->call('openCreate')->assertSet('showForm', true)
            ->set('name', '/Summarise')->set('description', 'Shorter')->set('promptTemplate', 'Summarise {{selection}}')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false)
            ->assertSee('summarise');

        $cmd = DocumentSlashCommand::sole();
        $this->assertSame('summarise', $cmd->name);
        $this->assertNull($cmd->team_id);

        Livewire::actingAs($user)->test(SlashCommandManager::class)
            ->call('editCommand', $cmd->id)->assertSet('name', 'summarise')->assertSet('description', 'Shorter')
            ->set('promptTemplate', 'Make it brief')->call('save');
        $this->assertSame('Make it brief', $cmd->fresh()->prompt_template);

        Livewire::actingAs($user)->test(SlashCommandManager::class)->call('deleteCommand', $cmd->id);
        $this->assertSame(0, DocumentSlashCommand::count());
    }

    public function test_a_shared_command_is_seen_by_the_team_but_only_its_owner_can_change_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mate = User::factory()->create(['current_team_id' => $owner->currentTeam->id]);
        $owner->currentTeam->users()->attach($mate, ['role' => 'editor']);

        Livewire::actingAs($owner)->test(SlashCommandManager::class)
            ->set('name', 'brief')->set('promptTemplate', 'Be brief')->set('shareWithTeam', true)->call('save');
        $cmd = DocumentSlashCommand::sole();
        $this->assertSame($owner->currentTeam->id, $cmd->team_id);

        Livewire::actingAs($mate)->test(SlashCommandManager::class)
            ->assertSee('brief')
            ->call('editCommand', $cmd->id)->assertSet('editingId', null)
            ->call('deleteCommand', $cmd->id);
        $this->assertSame(1, DocumentSlashCommand::count(), 'a teammate cannot delete it');
    }

    public function test_a_command_needs_a_simple_name_and_a_prompt(): void
    {
        Livewire::actingAs(User::factory()->withPersonalTeam()->create())->test(SlashCommandManager::class)
            ->set('name', 'two words')->set('promptTemplate', '')->call('save')
            ->assertHasErrors(['name', 'promptTemplate']);
        $this->assertSame(0, DocumentSlashCommand::count());
    }
}
