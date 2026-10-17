<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Livewire\Documents\AiAssistant;
use App\Livewire\Documents\CommentThread;
use App\Livewire\Documents\Editor;
use App\Models\AiSuggestion;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A person who may read a document and not change it gets a READ ONLY editor.
 *
 * Who may change a document is DocumentPolicy::update(): the owner, a team
 * admin or editor, or a collaborator whose role is editor or admin. There is
 * no "commenter" role: a viewer (collaborator, team member, or anybody at all
 * when the document is public) reads, follows other people's edits, comments
 * (CommentThread asks only for view access) and exports, and changes nothing.
 *
 * The server draws the page from that answer (`canEdit`, and the `editable`
 * literal of the editor's x-data object), and every endpoint that writes
 * refuses such a person with 403 and changes nothing.
 */
class EditorReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function docJson(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    private function document(User $owner): Document
    {
        $this->seed(DocumentStyleSeeder::class);
        $store = app(DocumentStore::class);

        return $store->save($store->create($owner, 'Shared notes'), $this->docJson('Original words'), $owner);
    }

    /** @return array{0: User, 1: Document} */
    private function ownerAndDocument(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();

        return [$owner, $this->document($owner)];
    }

    private function collaborator(Document $doc, string $role): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function teamMember(User $owner, string $role): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function roles(): array
    {
        return [
            'owner' => ['owner', true],
            'collaborator editor' => ['collaborator:editor', true],
            'collaborator admin' => ['collaborator:admin', true],
            'collaborator viewer' => ['collaborator:viewer', false],
            'team admin' => ['team:admin', true],
            'team editor' => ['team:editor', true],
            'team viewer' => ['team:viewer', false],
            'stranger on a public document' => ['public', false],
        ];
    }

    /** @return array{0: User, 1: Document} the person to act as and the document */
    private function actor(string $kind): array
    {
        [$owner, $doc] = $this->ownerAndDocument();

        if ($kind === 'owner') {
            return [$owner, $doc];
        }
        if ($kind === 'public') {
            $doc->forceFill(['is_public' => true])->save();

            return [User::factory()->withPersonalTeam()->create(), $doc->fresh()];
        }

        [$source, $role] = explode(':', $kind);

        return [$source === 'team' ? $this->teamMember($owner, $role) : $this->collaborator($doc, $role), $doc];
    }

    #[DataProvider('roles')]
    public function test_the_editor_is_editable_only_for_somebody_who_may_change_the_document(string $kind, bool $editable): void
    {
        [$user, $doc] = $this->actor($kind);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertOk()
            ->assertViewHas('canEdit', $editable);

        $page = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();
        $this->assertStringContainsString($editable ? 'editable: true,' : 'editable: false,', $page);
        $this->assertStringNotContainsString($editable ? 'editable: false,' : 'editable: true,', $page);
        $this->assertSame($editable, $user->can('update', $doc));
    }

    public function test_a_viewer_is_told_in_plain_words_and_the_status_says_read_only_never_saved(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');

        $page = $this->actingAs($viewer)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();

        $this->assertStringContainsString('You can read this document but not change it.', $page);
        $this->assertStringContainsString('data-read-only-word', $page);
        $this->assertStringNotContainsString('data-saved-word', $page);

        $ownersPage = $this->actingAs($owner)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();
        $this->assertStringContainsString('data-saved-word', $ownersPage);
        $this->assertStringNotContainsString('data-read-only-word', $ownersPage);
    }

    public function test_a_viewers_page_draws_no_control_that_changes_the_document(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');

        $viewerPage = $this->actingAs($viewer)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();
        $ownerPage = $this->actingAs($owner)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();

        // The title and the style are read, not edited.
        $this->assertMatchesRegularExpression('/<input[^>]*id="doc-title"[^>]*\breadonly\b/s', $viewerPage);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="doc-title"[^>]*\breadonly\b/s', $ownerPage);
        $this->assertMatchesRegularExpression('/<select[^>]*id="doc-style-picker"[^>]*\bdisabled\b/s', $viewerPage);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="doc-style-picker"[^>]*\bdisabled\b/s', $ownerPage);

        foreach (['Import it', 'Inline code', 'Suggest instead of editing', 'Start voice typing', '>Move<'] as $writes) {
            $this->assertStringNotContainsString($writes, $viewerPage, "a viewer is offered: {$writes}");
            $this->assertStringContainsString($writes, $ownerPage, "the owner lost: {$writes}");
        }

        // What a reader may do is still there.
        foreach (['Show comments', 'PDF', 'Markdown', 'Save to Dot.Files'] as $reads) {
            $this->assertStringContainsString($reads, $viewerPage, "a viewer lost: {$reads}");
        }
    }

    public function test_the_save_of_a_viewer_is_refused_and_nothing_is_stored(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');
        $before = $doc->fresh();

        Livewire::actingAs($viewer)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->docJson('Vandalism'), $before->version)
            ->assertForbidden();

        $after = $doc->fresh();
        $this->assertSame($before->version, $after->version);
        $this->assertSame('Original words', $after->search_text);
        $this->assertSame($before->content_json, $after->content_json);
    }

    public function test_a_viewer_cannot_retitle_restyle_or_move_the_document(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');

        // A refused call leaves a component with no usable snapshot: one component per attempt.
        $open = fn () => Livewire::actingAs($viewer)->test(Editor::class, ['uuid' => $doc->uuid]);

        $open()->set('title', 'Renamed by a viewer')->call('saveTitle')->assertForbidden();
        $open()->call('setStyle', 'letter')->assertForbidden();

        $fresh = $doc->fresh();
        $this->assertSame('Shared notes', $fresh->title);
        $this->assertSame($doc->style_key, $fresh->style_key);
        $this->assertSame($doc->version, $fresh->version);
    }

    public function test_a_viewer_cannot_accept_or_drop_a_suggestion_or_apply_an_ai_result(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');
        $suggestion = AiSuggestion::create([
            'document_id' => $doc->id,
            'user_id' => $owner->id,
            'suggestion_text' => '<p>Suggested words</p>',
            'created_at' => now(),
        ]);

        $open = fn () => Livewire::actingAs($viewer)->test(Editor::class, ['uuid' => $doc->uuid]);
        $open()->call('acceptSuggestion', $suggestion->id)->assertForbidden();
        $open()->call('rejectSuggestion', $suggestion->id)->assertForbidden();

        $assistant = Livewire::actingAs($viewer)->test(AiAssistant::class, ['document' => $doc])
            ->set('result', '<p>Replace everything</p>');
        $assistant->call('applyResult')->assertForbidden();

        $this->assertSame($doc->version, $doc->fresh()->version);
        $this->assertNull($suggestion->fresh()->accepted_at);
    }

    public function test_an_editor_can_still_apply_an_ai_result(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();

        Livewire::actingAs($owner)->test(AiAssistant::class, ['document' => $doc])
            ->set('result', '<p>Replace everything</p>')
            ->call('applyResult')
            ->assertOk()
            ->assertDispatched('ai-apply');
    }

    public function test_the_beacon_and_image_endpoints_refuse_a_viewer_and_change_nothing(): void
    {
        Storage::fake('public');
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');
        $before = $doc->fresh();

        $this->actingAs($viewer)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->docJson('Vandalism'), 'base_version' => $before->version])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('documents.images.store', $doc->uuid), ['image' => UploadedFile::fake()->image('x.png', 10, 10)])
            ->assertForbidden();
        $this->assertSame([], Storage::disk('public')->files('document-images'));

        $this->actingAs($viewer)
            ->post(route('documents.import', $doc->uuid), ['file' => UploadedFile::fake()->createWithContent('x.md', '# Imported')])
            ->assertForbidden();

        $after = $doc->fresh();
        $this->assertSame($before->version, $after->version);
        $this->assertSame($before->content_json, $after->content_json);
        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('version_number', '>', $before->version)->count());
    }

    public function test_the_editor_still_accepts_the_save_endpoint_from_an_editor(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $editor = $this->collaborator($doc, 'editor');

        $this->actingAs($editor)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->docJson('Edited'), 'base_version' => $doc->fresh()->version])
            ->assertOk();

        $this->assertSame('Edited', $doc->fresh()->search_text);
    }

    public function test_a_viewer_keeps_following_the_document_through_the_poll(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');

        // The viewer's page was drawn at the current version; somebody saves.
        $drawnAt = $doc->fresh()->version;
        app(DocumentStore::class)->save($doc->fresh(), $this->docJson('Newer words'), $owner);

        $this->actingAs($viewer)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => $drawnAt, 'tab' => 'viewer-tab'])
            ->assertOk()
            ->assertJson(['changed' => true, 'version' => $drawnAt + 1])
            ->assertJsonPath('json.content.0.content.0.text', 'Newer words');

        // And an up-to-date poll is the cheap answer, writing nothing to the document.
        $versionBefore = $doc->fresh()->version;
        $this->actingAs($viewer)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => $versionBefore, 'tab' => 'viewer-tab'])
            ->assertOk()
            ->assertJson(['changed' => false]);
        $this->assertSame($versionBefore, $doc->fresh()->version);
    }

    public function test_a_viewer_may_still_export_and_comment(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');

        $this->actingAs($viewer)->get(route('documents.export', [$doc->uuid, 'html']))->assertOk();

        Livewire::actingAs($viewer)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'A reader may say something')
            ->call('postComment')
            ->assertOk();

        $this->assertSame(1, Comment::where('document_id', $doc->id)->where('user_id', $viewer->id)->count());
    }

    public function test_a_viewer_is_not_offered_the_share_page(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->collaborator($doc, 'viewer');

        $this->actingAs($viewer)->get(route('documents.share', $doc->uuid))->assertForbidden();
    }

    public function test_dropping_a_role_to_viewer_takes_effect_on_the_next_render_and_the_next_save(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $person = $this->collaborator($doc, 'editor');

        $page = Livewire::actingAs($person)->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertViewHas('canEdit', true);

        DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $person->id)->update(['role' => 'viewer']);

        // The page that is already open is refused on its next save...
        $page->call('saveContent', $this->docJson('After the downgrade'), $doc->fresh()->version)->assertForbidden();
        // ...and the next page drawn for the same person is the read only one.
        Livewire::actingAs($person->fresh())->test(Editor::class, ['uuid' => $doc->uuid])->assertViewHas('canEdit', false);
        $this->assertSame('Original words', $doc->fresh()->search_text);
    }

    public function test_a_page_that_is_drawn_again_after_a_downgrade_becomes_the_read_only_page(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $person = $this->collaborator($doc, 'editor');

        $page = Livewire::actingAs($person)->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertViewHas('canEdit', true);

        DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $person->id)->update(['role' => 'viewer']);

        // What the browser does after a refused save: render again.
        $page->call('refreshPresence')
            ->assertViewHas('canEdit', false)
            ->assertSee('editable: false', false);
    }

    public function test_the_page_can_ask_the_server_whether_this_person_may_still_change_the_document(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $person = $this->collaborator($doc, 'editor');

        $page = Livewire::actingAs($person)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('canEdit')
            ->assertReturned(true);

        DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $person->id)->update(['role' => 'viewer']);

        $page->call('canEdit')->assertReturned(false);
        $this->assertSame('Original words', $doc->fresh()->search_text, 'asking changes nothing');
    }

    public function test_a_team_viewer_who_is_an_editor_collaborator_gets_an_editable_page_and_a_team_owner_does_too(): void
    {
        [$owner, $doc] = $this->ownerAndDocument();
        $viewer = $this->teamMember($owner, 'viewer');
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $viewer->id, 'role' => 'editor']);

        Livewire::actingAs($viewer)->test(Editor::class, ['uuid' => $doc->uuid])->assertViewHas('canEdit', true);

        // A document a member wrote, in a team somebody else owns.
        $member = User::factory()->withPersonalTeam()->create();
        $teamOwner = User::factory()->withPersonalTeam()->create();
        $teamOwner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $theirs = app(DocumentStore::class)->create($member, 'Theirs');
        $theirs->forceFill(['team_id' => $teamOwner->currentTeam->id])->save();

        Livewire::actingAs($teamOwner)->test(Editor::class, ['uuid' => $theirs->uuid])->assertViewHas('canEdit', true);
    }
}
