<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\DocumentSettings;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentSettingsPageSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_page_setup_is_saved_onto_the_document(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report');

        Livewire::actingAs($user)
            ->test(DocumentSettings::class, ['uuid' => $doc->uuid])
            ->set('pageSize', 'Letter')
            ->set('orientation', 'landscape')
            ->set('marginTop', '30mm')
            ->set('marginRight', '15mm')
            ->set('marginBottom', '30mm')
            ->set('marginLeft', '15mm')
            ->set('header', '{{ title }}')
            ->set('footer', 'Page {{ page }} of {{ pages }}')
            ->call('savePageSetup')
            ->assertHasNoErrors();

        $doc->refresh();
        $this->assertSame([
            'size' => 'Letter',
            'orientation' => 'landscape',
            'margins' => ['top' => '30mm', 'right' => '15mm', 'bottom' => '30mm', 'left' => '15mm'],
            'header' => '{{ title }}',
            'footer' => 'Page {{ page }} of {{ pages }}',
        ], $doc->page_setup);
    }

    public function test_invalid_orientation_is_rejected(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report');

        Livewire::actingAs($user)
            ->test(DocumentSettings::class, ['uuid' => $doc->uuid])
            ->set('orientation', 'sideways')
            ->call('savePageSetup')
            ->assertHasErrors(['orientation']);

        $doc->refresh();
        $this->assertNull($doc->page_setup);
    }
}
