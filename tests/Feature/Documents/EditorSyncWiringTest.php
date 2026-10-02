<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
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

        // The three notices, each shown by Alpine and hidden until Alpine runs.
        foreach (['conflict', 'setAside', 'syncNotice'] as $state) {
            $notice = $bar->querySelector('.doc-notice[x-show="'.$state.'"]');
            $this->assertNotNull($notice, "the {$state} notice is in the bar");
            $this->assertTrue($notice->hasAttribute('x-cloak'), "the {$state} notice is hidden until Alpine runs");
            $this->assertNull($strip->querySelector('[x-show="'.$state.'"] button'));
        }
        $this->assertStringContainsString(
            'this document was changed elsewhere while you were typing',
            $bar->querySelector('[x-show="conflict"]')->textContent,
        );
        $this->assertStringContainsString('Your text was set aside.', $bar->querySelector('[x-show="setAside"]')->textContent);
        $this->assertNotNull($bar->querySelector('[x-show="syncNotice"] [x-text="syncNotice"]'));

        // The three buttons, real buttons, in reading order, each in its notice.
        $handlers = [];
        foreach ($bar->querySelectorAll('button') as $button) {
            $this->assertSame('button', $button->getAttribute('type'));
            $handlers[] = $button->getAttribute('@click');
        }
        $this->assertSame(['keepMine()', 'loadTheirs()', 'putBack()'], $handlers);
        $this->assertNotNull($bar->querySelector('[x-show="conflict"] button[\@click="keepMine()"][title]'));
        $loadTheirs = $bar->querySelector('[x-show="conflict"] button[\@click="loadTheirs()"][title]');
        $this->assertNotNull($loadTheirs);
        $this->assertSame('!conflict || !conflict.ready', $loadTheirs->getAttribute(':disabled'));
        $this->assertNotNull($bar->querySelector('[x-show="setAside"] button[\@click="putBack()"]'));

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
     * Alpine binds an element Livewire ADDS on a later render to the newest
     * data object, whose state never changes (.ai/rules/livewire.md). So
     * nothing in the notice bar may come and go with a Blade condition: every
     * element is rendered on every render and shown or hidden with x-show.
     */
    public function test_nothing_in_the_notice_bar_is_rendered_conditionally(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/documents/editor.blade.php'));

        // From the bar's opening tag to the closing tag at the same indentation.
        $this->assertSame(1, preg_match('/^( *)<div class="doc-notices".*?^\1<\/div>$/ms', $blade, $bar));
        $this->assertStringContainsString('putBack()', $bar[0]);
        $this->assertDoesNotMatchRegularExpression('/@(if|unless|else|error|isset|empty|foreach|auth|can)\b/', $bar[0]);
        $this->assertStringNotContainsString('wire:', $bar[0]);
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
