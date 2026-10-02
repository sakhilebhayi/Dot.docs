<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The editor page is where the sync engine is started. None of that script
 * runs under PHPUnit; what can be guarded here is that the page carries the
 * pieces, that they sit inside an Alpine component the browser can still
 * read, and that the page no longer carries what they replaced.
 */
class EditorSyncWiringTest extends TestCase
{
    use RefreshDatabase;

    private function editorHtml(): string
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Wired');

        return $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();
    }

    /**
     * One row of the notice bar, found by the state that switches it.
     */
    private function notice(Element $bar, string $state): Element
    {
        foreach ($bar->querySelectorAll('.doc-notice') as $row) {
            if ($row->getAttribute('x-bind:hidden') === '!'.$state) {
                return $row;
            }
        }

        $this->fail("the {$state} notice is not in the bar");
    }

    public function test_the_editor_page_starts_the_sync_engine_against_its_own_document(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Wired');

        $html = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();

        $this->assertStringContainsString(route('documents.sync', $doc->uuid), $html);

        // The whole Alpine component is ONE double-quoted HTML attribute.
        // `[^"]*` reads it exactly as a browser does, so a double quote
        // anywhere inside the component - a comment is enough - ends the
        // attribute there. The match is anchored to what must FOLLOW the
        // attribute in the template, x-init, so an attribute that is cut
        // short does not match at all - even when the cut happens to fall
        // directly after a closing brace.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertStringContainsString('createSyncEngine', $alpine[1]);
        $this->assertStringContainsString('getBaseVersion', $alpine[1]);
        $this->assertStringContainsString('refreshPresence', $alpine[1]);
        $this->assertStringEndsWith('}', trim($alpine[1]));

        // The page's decisions are made by resources/js/editor/sync/host.js,
        // which tests/js/sync.host.test.js runs. Those tests prove nothing
        // about this page unless the page really hands over to the module:
        // a host built from the data object the call was made on, and the
        // engine given that host's callbacks.
        $this->assertStringContainsString('createSyncHost(this', $alpine[1]);
        $this->assertStringContainsString('this.syncHost().engineHost()', $alpine[1]);

        // The unload beacon reads, at the moment of leaving, whether this
        // tab owes an overwrite (Keep mine or Put it back). Without this
        // option the beacon never says so and the server keeps nothing of
        // the version it replaces.
        $this->assertStringContainsString('getOverwrite: () => this.overwriteOwed', $alpine[1]);
    }

    /**
     * Asserted on the buttons' markup, not on their words: the words also
     * appear in the component's own comments, which would pass this before
     * any button existed.
     */
    public function test_the_page_offers_both_ways_out_of_a_conflict_and_a_way_back(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('@click="keepMine()"', $html);
        $this->assertStringContainsString('@click="loadTheirs()"', $html);
        $this->assertStringContainsString('@click="putBack()"', $html);
    }

    /**
     * The conflict notice, the set-aside notice and the sync notice used to
     * sit in the one-line status strip, where their buttons ended up outside
     * a laptop-sized window or underneath the dock. They have a bar of their
     * own now: directly below the editor's bar, above the paper, inside the
     * editor column.
     *
     * Read as a DOM, the way a browser reads the page, so "inside" and
     * "outside" mean what they mean on screen.
     */
    public function test_the_notices_with_actions_are_in_a_bar_of_their_own_outside_the_status_strip(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $this->assertCount(1, $dom->querySelectorAll('.doc-notices'));
        $bar = $dom->querySelector('.doc-notices');
        $strip = $dom->querySelector('.doc-status');
        $paper = $dom->querySelector('#doc-paper');
        $this->assertNotNull($bar);
        $this->assertNotNull($strip);
        $this->assertNotNull($paper);

        // Where it is: in the editor column, straight after the editor's
        // bar, before the paper, and not inside the strip or the bar itself.
        $this->assertNotNull($bar->closest('.editor'));
        $this->assertNotNull($dom->querySelector('.doc-bar + .doc-notices'));
        $this->assertNull($bar->closest('.doc-status'));
        $this->assertNull($bar->closest('.doc-bar'));
        $this->assertNotSame(
            0,
            $bar->compareDocumentPosition($paper) & Node::DOCUMENT_POSITION_FOLLOWING,
            'the notice bar comes before the paper',
        );

        // It is announced.
        $this->assertSame('status', $bar->getAttribute('role'));
        $this->assertSame('polite', $bar->getAttribute('aria-live'));

        // The three notices, in this order. Each is hidden in the markup the
        // server sends, so it does not show before Alpine runs.
        $rows = [];
        foreach ($bar->querySelectorAll('.doc-notice') as $row) {
            $rows[] = $row->getAttribute('x-bind:hidden');
        }
        $this->assertSame(['!conflict', '!setAside', '!syncNotice'], $rows);
        foreach (['conflict', 'setAside', 'syncNotice'] as $state) {
            $this->assertTrue($this->notice($bar, $state)->hasAttribute('hidden'), "the {$state} notice is hidden until Alpine runs");
            $this->assertNull($strip->querySelector('[x-show="'.$state.'"] button'));
        }
        $this->assertStringContainsString(
            'this document was changed elsewhere while you were typing',
            $this->notice($bar, 'conflict')->textContent,
        );
        $this->assertStringContainsString('Your text was set aside.', $this->notice($bar, 'setAside')->textContent);
        $this->assertNotNull($this->notice($bar, 'syncNotice')->querySelector('[x-text="syncNotice"]'));

        // The three buttons, real buttons, in reading order, each in its notice.
        $handlers = [];
        foreach ($bar->querySelectorAll('button') as $button) {
            $this->assertSame('button', $button->getAttribute('type'));
            $handlers[] = $button->getAttribute('@click');
        }
        $this->assertSame(['keepMine()', 'loadTheirs()', 'putBack()'], $handlers);
        $this->assertNotNull($this->notice($bar, 'conflict')->querySelector('button[\@click="keepMine()"][title]'));
        $loadTheirs = $this->notice($bar, 'conflict')->querySelector('button[\@click="loadTheirs()"][title]');
        $this->assertNotNull($loadTheirs);
        $this->assertSame('!conflict || !conflict.ready', $loadTheirs->getAttribute(':disabled'));
        $this->assertNotNull($this->notice($bar, 'setAside')->querySelector('button[\@click="putBack()"]'));

        // And nowhere else on the page: the strip carries no button at all.
        foreach (['keepMine()', 'loadTheirs()', 'putBack()'] as $handler) {
            $this->assertSame(1, substr_count($html, '@click="'.$handler.'"'));
        }
        $this->assertCount(0, $strip->querySelectorAll('button'));

        // While the conflict notice shows, the strip says Not saved and no
        // more: the sentence is in the bar.
        $word = $strip->querySelector('[x-show="conflict"]');
        $this->assertNotNull($word);
        $this->assertSame('Not saved', trim($word->textContent));
        $this->assertStringNotContainsString('changed elsewhere', $strip->textContent);
        $this->assertStringNotContainsString('set aside', $strip->textContent);
    }

    /**
     * Livewire's morph must not reach the notice bar at all, and a notice
     * must not wait for an animation frame to go away.
     *
     * Every morph initialises the INCOMING copy of every element it patches
     * against the root's newest Alpine data object, whose conflict, setAside
     * and syncNotice never change, and copies the outcome onto the live
     * element. Only x-show is guarded against that. A re-render while the
     * conflict notice showed (somebody leaving is enough) disabled Load
     * theirs, and one while a sync notice showed removed its sentence for
     * good, leaving a red dot and no words. So the bar is `wire:ignore`:
     * Livewire skips it before it clones anything.
     *
     * And x-show hides on a later change only inside requestAnimationFrame,
     * so in a tab that is not being painted a notice stayed up although its
     * state was gone. The rows are switched with the `hidden` attribute
     * instead, which Alpine writes in the same flush as the change.
     */
    public function test_the_notice_bar_is_out_of_livewires_morph_and_its_rows_are_switched_with_the_hidden_attribute(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $bar = $dom->querySelector('.doc-notices');
        $strip = $dom->querySelector('.doc-status');
        $this->assertNotNull($bar);
        $this->assertNotNull($strip);

        $this->assertTrue($bar->hasAttribute('wire:ignore'), 'the notice bar is skipped by the morph');

        // Not x-show and not x-cloak, anywhere in the bar: the attribute is
        // in the markup from the start and Alpine switches it.
        $this->assertCount(0, $bar->querySelectorAll('[x-show], [x-cloak]'));
        $this->assertCount(3, $bar->querySelectorAll('.doc-notice'));
        foreach (['conflict', 'setAside', 'syncNotice'] as $state) {
            $row = $this->notice($bar, $state);
            $this->assertTrue($row->hasAttribute('hidden'));
            $this->assertSame('!'.$state, $row->getAttribute('x-bind:hidden'));
        }

        // `.doc-notice` is display:flex, which beats the browser's own rule
        // for [hidden]: without this rule all three notices show for good.
        $css = file_get_contents(resource_path('css/shell.css'));
        $this->assertMatchesRegularExpression('/\.doc-notice\[hidden\][^{}]*\{\s*display:\s*none;\s*\}/', $css);

        // The one sentence in the strip that Alpine writes (a refused AI
        // result or suggestion) was emptied by the same morph. It is
        // skipped by the morph as well.
        $aiError = $strip->querySelector('[x-show="aiError"]');
        $this->assertNotNull($aiError);
        $this->assertTrue($aiError->hasAttribute('wire:ignore'));
        $this->assertNotNull($aiError->querySelector('[x-text="aiError"]'));
    }

    /**
     * An element under `wire:ignore` is never brought up to date by a later
     * render, so nothing in the notice bar may depend on what the server
     * renders: no Blade condition, no echoed value, no Livewire directive
     * other than the one that takes the bar out of the morph. It is static
     * markup that Alpine alone shows, hides and fills.
     */
    public function test_nothing_in_the_notice_bar_depends_on_what_the_server_renders(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/documents/editor.blade.php'));

        // From the bar's opening tag to the closing tag at the same indentation.
        $this->assertSame(1, preg_match('/^( *)<div class="doc-notices"[^>]*>.*?^\1<\/div>$/ms', $blade, $bar));
        $this->assertStringContainsString('putBack()', $bar[0]);
        $this->assertDoesNotMatchRegularExpression('/@(if|unless|else|error|isset|empty|foreach|auth|can)\b/', $bar[0]);
        // No echo. (`{{--` opens a Blade comment, which renders nothing.)
        $this->assertDoesNotMatchRegularExpression('/\{\{(?!--)|\{!!/', $bar[0]);
        // `wire:ignore` on the bar's own tag, and no other Livewire directive.
        $this->assertMatchesRegularExpression('/^ *<div class="doc-notices"[^>]*\swire:ignore[\s>]/', $bar[0]);
        $this->assertSame(1, substr_count($bar[0], 'wire:'));
    }

    /**
     * heartbeat() and leaving() are empty methods kept for tabs opened
     * before this shipped. The page itself must not call them any more.
     */
    public function test_the_page_no_longer_calls_the_old_presence_methods(): void
    {
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('.heartbeat()', $html);
        $this->assertStringNotContainsString('.leaving()', $html);
        $this->assertStringNotContainsString('.user.joined', $html);
        $this->assertStringNotContainsString('.user.left', $html);
        $this->assertStringNotContainsString('beforeunload', $html);
    }
}
