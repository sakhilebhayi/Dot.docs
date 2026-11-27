<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentMedia;
use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R2-1 / S-13: a picture in a document is seen by exactly the people who can read the document. */
class DocumentMediaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Document, 2: string} */
    private function pictured(): array
    {
        Storage::fake('local');
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'With a picture');

        $url = $this->actingAs($owner)->post(route('documents.images.store', $doc->uuid), [
            'image' => UploadedFile::fake()->image('shot.png', 20, 20),
        ])->assertOk()->json('url');

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return [$owner, $doc, $url];
    }

    public function test_the_owner_sees_it_and_a_stranger_or_guest_gets_404(): void
    {
        [$owner, , $url] = $this->pictured();

        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get($url)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'max-age=3600, private');
    }

    public function test_a_visitor_of_a_live_public_link_sees_it_and_not_after_it_expires(): void
    {
        [, $doc, $url] = $this->pictured();

        $doc->forceFill(['is_public' => true])->save();
        $this->get($url)->assertOk();

        $doc->forceFill(['share_expires_at' => now()->subMinute()])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_a_password_protected_link_shows_it_only_after_unlocking_in_this_session(): void
    {
        [, $doc, $url] = $this->pictured();
        $doc->forceFill(['is_public' => true, 'share_password' => Hash::make('open-sesame')])->save();

        $this->get($url)->assertNotFound();

        $this->post(route('documents.shared.unlock', $doc->uuid), ['password' => 'wrong']);
        $this->get($url)->assertNotFound();

        $this->post(route('documents.shared.unlock', $doc->uuid), ['password' => 'open-sesame'])->assertOk();
        $this->get($url)->assertOk();
    }

    public function test_the_route_refuses_paths_that_are_not_one_documents_file(): void
    {
        [$owner, $doc] = $this->pictured();

        $this->actingAs($owner)->get('/media/'.$doc->uuid.'/..%2F..%2F.env')->assertNotFound();
        $this->actingAs($owner)->get('/media/'.$doc->uuid.'/missing.webp')->assertNotFound();
        $this->assertNull(DocumentMedia::path('/media/'.$doc->uuid.'/../x.webp'));
    }

    public function test_the_page_renders_the_private_src(): void
    {
        [$owner, $doc, $url] = $this->pictured();
        $json = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $url]]]];
        app(DocumentStore::class)->save($doc->fresh(), $json, $owner);

        $this->assertStringContainsString('src="'.$url.'"', (string) $doc->fresh()->content);
    }

    public function test_changing_the_link_password_ends_an_earlier_unlock(): void
    {
        [, $doc, $url] = $this->pictured();
        $doc->forceFill(['is_public' => true, 'share_password' => Hash::make('first')])->save();

        $this->post(route('documents.shared.unlock', $doc->uuid), ['password' => 'first'])->assertOk();
        $this->get($url)->assertOk();

        $doc->forceFill(['share_password' => Hash::make('second')])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_a_copy_made_by_someone_who_can_read_the_source_gets_its_own_copy_of_the_picture(): void
    {
        [$owner, , $url] = $this->pictured();
        $json = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $url]]]];

        $copy = app(DocumentStore::class)->create($owner, 'From a template', $json);
        $src = $copy->content_json['content'][0]['attrs']['src'];

        $this->assertStringStartsWith('/media/'.$copy->uuid.'/', $src);
        $this->assertNotNull(DocumentMedia::path($src));
    }

    public function test_someone_who_cannot_read_the_source_cannot_pull_its_picture_into_their_document(): void
    {
        [, , $url] = $this->pictured();
        $stranger = User::factory()->withPersonalTeam()->create();
        $theirs = app(DocumentStore::class)->create($stranger, 'Mine');

        $saved = app(DocumentStore::class)->save($theirs, ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $url]]]], $stranger);

        $this->assertSame($url, $saved->content_json['content'][0]['attrs']['src'], 'left pointing at the source, not copied');
        $this->assertCount(0, Storage::disk('local')->files('document-media/'.$theirs->uuid));
    }
}
