<?php

namespace Tests\Feature\Accessibility;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Documents\AiAssistant;
use App\Livewire\Documents\AiChat;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\Index;
use App\Livewire\Documents\ShareManager;
use App\Livewire\Files\Navigator;
use App\Models\DocumentCollaborator;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R2-9: findings from the independent accessibility audit - live regions that say what changed once, names that
 * say which row an action acts on, and focus that is not dropped. Read as a DOM where "inside" matters.
 */
class R29AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private function signedIn(): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Olive Owner']);
        $this->actingAs($user);

        return $user;
    }

    /** WCAG 4.1.3: one live region for save state, not the strip and the top bar both. */
    public function test_the_editor_has_one_live_region_for_save_state(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = $this->signedIn();
        $doc = app(DocumentStore::class)->create($user, 'Live');

        $dom = $this->dom($this->get(route('documents.edit', $doc->uuid))->assertOk()->getContent());

        $word = $dom->querySelector('#shell-save');
        $this->assertNotNull($word);
        $this->assertNull($word->getAttribute('aria-live'), 'the visible word changes on every pause and is not live');

        $strip = $dom->querySelector('.doc-status');
        $this->assertNotNull($strip);
        $this->assertNull($strip->getAttribute('aria-live'), 'the status strip is visual only');
        $this->assertNull($strip->getAttribute('role'));

        $this->assertCount(1, $dom->querySelectorAll('#shell-save-said'));
        $said = $dom->querySelector('#shell-save-said');
        $this->assertSame('status', $said->getAttribute('role'));
        $this->assertSame('', trim($said->textContent), 'nothing is announced on load');
    }

    public function test_pages_without_a_document_have_no_save_announcer_and_say_skip_to_the_content(): void
    {
        $this->signedIn();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $dom = $this->dom($html);

        $this->assertNull($dom->querySelector('#shell-save-said'));
        $skip = $dom->querySelector('a.skip-link');
        $this->assertSame('Skip to the content', trim($skip->textContent));
        $this->assertSame('#canvas', $skip->getAttribute('href'));
        $this->assertNotNull($dom->querySelector('main#canvas'), 'the skip link has somewhere to go');
    }

    public function test_the_editor_skip_link_says_document_and_lands_on_the_canvas(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = $this->signedIn();
        $doc = app(DocumentStore::class)->create($user, 'Skip');

        $dom = $this->dom($this->get(route('documents.edit', $doc->uuid))->assertOk()->getContent());

        $this->assertSame('Skip to the document', trim($dom->querySelector('a.skip-link')->textContent));
        $this->assertNotNull($dom->querySelector('main#canvas'));
    }

    /** Presence initials carry the person's name, not only a title tooltip. */
    public function test_presence_initials_are_named_for_the_person(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = $this->signedIn();
        $doc = app(DocumentStore::class)->create($user, 'Presence');

        $people = [];
        foreach (['Ada Lovelace', 'Bo', 'Cy', 'Di', 'Ed', 'Flo'] as $i => $name) {
            $people[] = ['id' => 1000 + $i, 'name' => $name, 'avatar' => null];
        }

        $html = Livewire::test(Editor::class, ['uuid' => $doc->uuid])->set('activeUsers', $people)->html();
        $dom = $this->dom($html);
        $faces = $dom->querySelectorAll('.presence .presence-face');

        $this->assertCount(5, $faces);
        $first = $faces->item(0);
        $this->assertSame('A', trim($first->querySelector('[aria-hidden="true"]')->textContent));
        $this->assertSame('Ada Lovelace', trim($first->querySelector('.sr-only')->textContent));
        $this->assertSame('and 2 more', trim($faces->item(4)->querySelector('.sr-only')->textContent));
    }

    /** The assistant's thread is a persistent log; Thinking is said by a status line that is always there. */
    public function test_the_assistant_thread_is_a_log_with_a_persistent_status(): void
    {
        $user = $this->signedIn();
        $doc = app(DocumentStore::class)->create($user, 'Chat');

        $dom = $this->dom(Livewire::test(AiChat::class, ['document' => $doc])->html());

        $log = $dom->querySelector('.dock-chat-turns');
        $this->assertSame('log', $log->getAttribute('role'));
        $this->assertSame('dock-assistant', $log->getAttribute('aria-labelledby'));

        $status = $dom->querySelector('[data-assistant-status]');
        $this->assertSame('status', $status->getAttribute('role'));
        $this->assertNull($status->closest('[role="log"]'), 'live regions are not nested');
        $this->assertStringContainsString('Thinking', $status->textContent);
        $this->assertNotNull($status->querySelector('[wire\\:loading]'), 'only the words inside come and go');

        // The visible Thinking word inside the log is not read a second time.
        foreach ($log->querySelectorAll('[wire\\:loading]') as $visual) {
            $this->assertSame('true', $visual->getAttribute('aria-hidden'));
        }
    }

    /** WCAG 4.1.3: the Saved. confirmation sits in a status region that is always in the page. */
    public function test_the_action_message_is_announced_through_a_persistent_status(): void
    {
        $this->signedIn();
        $dom = $this->dom($this->get('/user/profile')->assertOk()->getContent());

        // The profile form's confirmation: a status region that never leaves, out of the footer's flow, holding
        // the word that appears.
        $form = $dom->querySelector('#name')->closest('form');
        $status = $form->querySelector('.panel-foot [role="status"]');
        $this->assertNotNull($status);
        $this->assertNull($status->getAttribute('x-show'), 'the region itself never leaves');
        $this->assertStringContainsString('sr-only', $status->getAttribute('class'));
        $this->assertSame('Saved.', trim($status->textContent));
        $this->assertNotNull($status->querySelector('[x-show="shown"]'));

        // The visible copy is the same word, not read twice.
        $visual = $form->querySelector('.panel-foot .micro');
        $this->assertSame('true', $visual->getAttribute('aria-hidden'));
        $this->assertSame('Saved.', trim($visual->textContent));
    }

    /** WCAG 1.4.3: the empty-document placeholder reaches 4.5:1 on the paper. */
    public function test_the_empty_document_placeholder_is_legible(): void
    {
        $css = file_get_contents(resource_path('css/paper.css'));
        $this->assertMatchesRegularExpression(
            '/p\.is-editor-empty:first-child::before\s*\{[^}]*color:\s*color-mix\(in srgb, var\(--paper-ink\) (\d+)%, transparent\)/s',
            $css,
        );
        preg_match('/p\.is-editor-empty:first-child::before\s*\{[^}]*color-mix\(in srgb, var\(--paper-ink\) (\d+)%/s', $css, $m);
        $share = (int) $m[1] / 100;

        $shell = file_get_contents(resource_path('css/shell.css'));
        preg_match('/--paper:\s*(#[0-9a-f]{6})/i', $shell, $paper);
        preg_match('/--paper-ink:\s*(#[0-9a-f]{6})/i', $shell, $ink);
        $this->assertSame(1, preg_match_all('/--paper:\s*#/', $shell), 'one paper colour, day and night');
        $this->assertSame(1, preg_match_all('/--paper-ink:\s*#/', $shell), 'one paper ink, day and night');

        $rgb = fn (string $hex) => array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $mixed = array_map(fn ($i, $p) => $p * (1 - $share) + $i * $share, $rgb($ink[1]), $rgb($paper[1]));
        $lum = function (array $c): float {
            $c = array_map(function ($v) {
                $s = $v / 255;

                return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
            }, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $ratio = ($lum($rgb($paper[1])) + 0.05) / ($lum($mixed) + 0.05);

        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('placeholder contrast %.2f:1', $ratio));
    }

    /** WCAG 2.4.6: Rename / Move / Delete say which row they act on. */
    public function test_navigator_row_actions_name_their_item(): void
    {
        Storage::fake('files');
        $user = $this->signedIn();
        $root = app(FilesService::class)->root($user->personalTeam());
        app(FilesService::class)->createFolder($root, 'Reports', $user);

        $dom = $this->dom(Livewire::test(Navigator::class)->html());
        $names = [];
        foreach ($dom->querySelectorAll('.list-row button.btn-sm') as $button) {
            $names[] = preg_replace('/\s+/', ' ', trim($button->textContent));
        }

        $this->assertSame(['Rename: Reports', 'Move: Reports', 'Delete: Reports'], $names);
        $this->assertSame(': Reports', $dom->querySelector('.list-row button.btn-sm .sr-only')->textContent);
    }

    public function test_folder_row_actions_and_the_breadcrumb_and_the_listing_status(): void
    {
        $user = $this->signedIn();
        $files = app(FilesService::class);
        $reports = $files->createFolder($files->root($user->personalTeam()), 'Reports', $user);
        $files->createFolder($reports, 'Quarterly', $user);

        $component = Livewire::test(Index::class);
        $dom = $this->dom($component->html());

        // At the root, "All documents" is where the listing stands.
        $crumbs = $dom->querySelectorAll('nav[aria-label="Folder path"] button');
        $this->assertSame('page', $crumbs->item(0)->getAttribute('aria-current'));

        $names = [];
        foreach ($dom->querySelectorAll('section[aria-labelledby="doc-folders"] .list-row .btn-sm') as $button) {
            $names[] = preg_replace('/\s+/', ' ', trim($button->textContent));
        }
        $this->assertSame(['Rename: Reports', 'Delete: Reports'], $names);

        $status = $dom->querySelector('[data-documents-status]');
        $this->assertSame('status', $status->getAttribute('role'));
        $this->assertStringContainsString('0 documents listed', preg_replace('/\s+/', ' ', $status->textContent));
        $this->assertStringContainsString('Filtering', $status->textContent);

        // Inside a folder, the last crumb is current and the root is not.
        $dom = $this->dom($component->call('openFolder', $reports->id)->html());
        $crumbs = $dom->querySelectorAll('nav[aria-label="Folder path"] button');
        $this->assertNull($crumbs->item(0)->getAttribute('aria-current'));
        $this->assertSame('Reports', trim($crumbs->item($crumbs->length - 1)->textContent));
        $this->assertSame('page', $crumbs->item($crumbs->length - 1)->getAttribute('aria-current'));
        $this->assertCount(1, $dom->querySelectorAll('nav[aria-label="Folder path"] [aria-current]'));

        app(DocumentStore::class)->create($user, 'One', null, [], $reports);
        $dom = $this->dom($component->call('openFolder', $reports->id)->html());
        $this->assertStringContainsString('1 document listed', preg_replace('/\s+/', ' ', $dom->querySelector('[data-documents-status]')->textContent));
    }

    /** After a collaborator is removed, focus goes to the list's heading and the result is said. */
    public function test_removing_a_collaborator_moves_focus_to_the_heading_and_announces_it(): void
    {
        $user = $this->signedIn();
        $doc = app(DocumentStore::class)->create($user, 'Shared');
        $other = User::factory()->withPersonalTeam()->create(['name' => 'Cole Colleague']);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $other->id, 'role' => 'editor']);

        $dom = $this->dom(Livewire::test(ShareManager::class, ['uuid' => $doc->uuid])->html());

        $heading = $dom->querySelector('#share-people');
        $this->assertSame('-1', $heading->getAttribute('tabindex'));

        $said = $dom->querySelector('[data-share-people-said]');
        $this->assertSame('status', $said->getAttribute('role'));
        $this->assertTrue($said->hasAttribute('wire:ignore'));
        $this->assertSame('said', $said->getAttribute('x-text'));

        $remove = $dom->querySelector('button[aria-label="Remove Cole Colleague"]');
        $click = $remove->getAttribute('@click');
        $this->assertStringContainsString('window.confirm(', $click);
        $this->assertStringContainsString('$wire.removeCollaborator(', $click);
        $this->assertStringContainsString('no longer has access', $click);
        $this->assertStringContainsString("document.getElementById('share-people')?.focus()", $click);
    }

    /** Escape closes the assistant palette from anywhere inside it, not only from the field. */
    public function test_escape_closes_the_ai_palette_from_anywhere_inside(): void
    {
        $user = $this->signedIn();
        $doc = app(DocumentStore::class)->create($user, 'Palette');

        $dom = $this->dom(Livewire::test(AiAssistant::class, ['document' => $doc])->set('showPalette', true)->html());

        $dialog = $dom->querySelector('[role="dialog"][aria-label="Assistant commands"]');
        $this->assertSame('$wire.closePalette()', $dialog->getAttribute('x-on:keydown.escape.stop'));
        $this->assertFalse($dom->querySelector('#ai-command')->hasAttribute('wire:keydown.escape'));
    }

    /** The role chooser on the team page is a labelled group around a real list (axe listitem). */
    public function test_the_team_role_chooser_keeps_its_list_semantics(): void
    {
        $user = $this->signedIn();
        $team = $user->currentTeam;
        $team->forceFill(['personal_team' => false])->save();

        $dom = $this->dom($this->get(route('teams.show', $team))->assertOk()->getContent());

        $list = $dom->querySelector('ul.role-list');
        $this->assertNotNull($list);
        $this->assertNull($list->getAttribute('role'), 'a list with a role is no longer a list');
        $this->assertSame('group', $list->parentElement->getAttribute('role'));
        $this->assertSame('add-member-role', $list->parentElement->getAttribute('aria-labelledby'));
    }
}
