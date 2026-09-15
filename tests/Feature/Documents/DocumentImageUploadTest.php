<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The editor's image upload endpoint.
 *
 * It called `$this->authorize()`, which the base Controller in this
 * application does not provide (no AuthorizesRequests trait), so every upload
 * answered 500 — found while staging the round-3 browser check for the
 * caption-during-upload guard, which needs a real upload to be in flight.
 */
class DocumentImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function doc(User $user)
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Pictures');
    }

    public function test_an_owner_can_upload_an_image_and_gets_a_url_back(): void
    {
        Storage::fake('public');
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $response = $this->actingAs($user)->post(route('documents.images.store', $doc->uuid), [
            'image' => UploadedFile::fake()->image('shot.png', 40, 30),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('/storage/document-images/', $response->json('url'));
        $this->assertCount(1, Storage::disk('public')->files('document-images'));
    }

    public function test_a_stranger_cannot_upload_into_someone_elses_document(): void
    {
        Storage::fake('public');
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->post(route('documents.images.store', $doc->uuid), [
                'image' => UploadedFile::fake()->image('shot.png', 40, 30),
            ])
            ->assertForbidden();

        $this->assertCount(0, Storage::disk('public')->files('document-images'));
    }

    public function test_a_guest_is_not_let_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->postJson(route('documents.images.store', $doc->uuid), [])->assertUnauthorized();
    }
}
