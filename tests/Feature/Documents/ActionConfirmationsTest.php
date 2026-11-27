<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\AiChat;
use App\Livewire\Documents\SaveAsTemplate;
use App\Livewire\Documents\ShareManager;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R2-9: a Livewire action says it worked in the page's banner (`banner-message`), in its own response. A session flash
 * from inside an action is never shown by that response and surfaced, stale, on a later page instead.
 */
class ActionConfirmationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_template_is_confirmed_on_the_page(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Board pack');

        Livewire::actingAs($owner)->test(SaveAsTemplate::class, ['document' => $doc])
            ->call('open')->call('save')
            ->assertDispatched('banner-message', style: 'success', message: 'Template saved successfully.');
        $this->assertNull(session('status'), 'nothing left behind for a later page');
    }

    public function test_saving_the_address_of_a_private_document_is_confirmed_too(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Plan');

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->set('slug', 'august-plan')->call('saveSlug')
            ->assertDispatched('banner-message', style: 'success', message: 'The address is saved.');
    }

    /** R2-9: with no model connected, the chat says it is a demonstration instead of showing "[mock:draft] …". */
    public function test_the_chat_says_plainly_when_no_ai_model_is_connected(): void
    {
        config(['ai.provider' => 'mock']);
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Plan');

        $html = Livewire::actingAs($owner)->test(AiChat::class, ['document' => $doc])
            ->set('message', 'Summarise this')->call('send')->html();

        $this->assertStringContainsString('This is a demonstration: no AI model is connected', $html);
        $this->assertStringNotContainsString('[mock:', $html);
    }
}
