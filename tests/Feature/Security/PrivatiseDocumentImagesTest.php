<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R2-9 / S-13: pictures stored before R2-1 move from the public folder to each document's private media. */
class PrivatiseDocumentImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_public_pictures_move_under_their_document_and_the_history_follows(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('document-images/old-photo.png', 'PNGDATA');
        $owner = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $json = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['src' => '/storage/document-images/old-photo.png']],
            ['type' => 'image', 'attrs' => ['src' => '/storage/document-images/gone.png']],
        ]];
        $doc = $store->save($store->create($owner, 'Photos'), $json, $owner, ['version' => 'named', 'label' => 'with photos']);

        $this->artisan('documents:privatise-images', ['--dry-run' => true])
            ->expectsOutputToContain('Would move 1 picture(s) in 1 document(s); 1 address(es) point at a file that is not there.')
            ->assertSuccessful();
        $this->assertStringContainsString('/storage/document-images/old-photo.png', json_encode($doc->fresh()->content_json, JSON_UNESCAPED_SLASHES));

        $this->artisan('documents:privatise-images')->expectsOutputToContain('Moved 1 picture(s)')->assertSuccessful();

        $new = '/media/'.$doc->uuid.'/old-photo.png';
        $current = json_encode($doc->fresh()->content_json, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString($new, $current);
        $this->assertStringContainsString('/storage/document-images/gone.png', $current, 'a missing file is left as it was');
        Storage::disk('local')->assertExists('document-media/'.$doc->uuid.'/old-photo.png');
        $this->assertSame('PNGDATA', Storage::disk('local')->get('document-media/'.$doc->uuid.'/old-photo.png'));

        $version = DB::table('document_versions')->where('document_id', $doc->id)->where('kind', 'named')->first();
        $this->assertStringContainsString(json_encode($new), (string) $version->content_json);
        Storage::disk('public')->assertExists('document-images/old-photo.png'); // nothing deleted without the flag

        // A later run with only --delete-public still finds what the earlier run moved.
        $this->artisan('documents:privatise-images', ['--delete-public' => true])->expectsOutputToContain('Deleted 1 public file(s)')->assertSuccessful();
        Storage::disk('public')->assertMissing('document-images/old-photo.png');
    }

    public function test_delete_public_removes_a_moved_file_once_nothing_points_at_it(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('document-images/a.png', 'A');
        $owner = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $store->save($store->create($owner, 'One'), ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/storage/document-images/a.png']]]], $owner);

        $this->artisan('documents:privatise-images', ['--delete-public' => true])
            ->expectsOutputToContain('Deleted 1 public file(s)')->assertSuccessful();
        Storage::disk('public')->assertMissing('document-images/a.png');
    }
}
