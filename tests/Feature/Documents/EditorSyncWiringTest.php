<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Livewire\Documents\Editor;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    /**
     * An element's text as a reader gets it: one line, single spaces.
     */
    private function words(?Element $element): string
    {
        $this->assertNotNull($element);

        return trim((string) preg_replace('/\s+/', ' ', $element->textContent));
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
        // And a way to decline the way back.
        $this->assertStringContainsString('@click="discardSetAside()"', $html);
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

        // The four notices, in this order. Each is hidden in the markup the
        // server sends, so it does not show before Alpine runs.
        $rows = [];
        foreach ($bar->querySelectorAll('.doc-notice') as $row) {
            $rows[] = $row->getAttribute('x-bind:hidden');
        }
        $this->assertSame(['!conflict', '!lost.length', '!setAside', '!syncNotice'], $rows);
        foreach (['conflict', 'lost.length', 'setAside', 'syncNotice'] as $state) {
            $this->assertTrue($this->notice($bar, $state)->hasAttribute('hidden'), "the {$state} notice is hidden until Alpine runs");
            $this->assertNull($strip->querySelector('[x-show="'.$state.'"] button'));
        }
        // A reload during a conflict no longer loses the text (the page
        // sets it aside as a draft and offers it back), so the sentence
        // does not forbid one. Since Phase 2 the notice appears only when
        // a merge could not be done, and the sentence says so.
        $this->assertSame(
            'Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one.',
            $this->words($this->notice($bar, 'conflict')->querySelector('.doc-notice-text')),
        );
        $this->assertStringContainsString('Your text was set aside.', $this->notice($bar, 'setAside')->textContent);
        $this->assertNotNull($this->notice($bar, 'syncNotice')->querySelector('[x-text="syncNotice"]'));

        // The four buttons, real buttons, in reading order, each in its notice.
        $handlers = [];
        foreach ($bar->querySelectorAll('button') as $button) {
            $this->assertSame('button', $button->getAttribute('type'));
            $handlers[] = $button->getAttribute('@click');
        }
        $this->assertSame(
            ['keepMine()', 'loadTheirs()', 'putBackLost(0)', 'putBackLost(1)', 'putBackLost(2)', 'putBackLost(3)', 'putBackLost(4)', 'putBackAllLost()', 'dismissLost()', 'putBack()', 'discardSetAside()'],
            $handlers,
        );
        $this->assertNotNull($this->notice($bar, 'conflict')->querySelector('button[\@click="keepMine()"][title]'));
        $loadTheirs = $this->notice($bar, 'conflict')->querySelector('button[\@click="loadTheirs()"][title]');
        $this->assertNotNull($loadTheirs);
        $this->assertSame('!conflict || !conflict.ready', $loadTheirs->getAttribute(':disabled'));
        $this->assertNotNull($this->notice($bar, 'setAside')->querySelector('button[\@click="putBack()"][title]'));
        $this->assertNotNull($this->notice($bar, 'setAside')->querySelector('button[\@click="discardSetAside()"][title]'));

        // And nowhere else on the page: the strip carries no button at all.
        foreach (['keepMine()', 'loadTheirs()', 'putBackLost(0)', 'putBackLost(1)', 'putBackLost(2)', 'putBackLost(3)', 'putBackLost(4)', 'putBackAllLost()', 'dismissLost()', 'putBack()', 'discardSetAside()'] as $handler) {
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
        $this->assertCount(4, $bar->querySelectorAll('.doc-notice'));
        foreach (['conflict', 'lost.length', 'setAside', 'syncNotice'] as $state) {
            $row = $this->notice($bar, $state);
            $this->assertTrue($row->hasAttribute('hidden'));
            $this->assertSame('!'.$state, $row->getAttribute('x-bind:hidden'));
        }

        // `.doc-notice` is display:flex, which beats the browser's own rule
        // for [hidden]: without this rule all three notices show for good.
        $css = file_get_contents(resource_path('css/shell.css'));
        $this->assertMatchesRegularExpression('/\.doc-notice\[hidden\][^{}]*\{\s*display:\s*none;\s*\}/', $css);

        // The descendant form is what hides the five item slots and the overflow line of the lost row, which are
        // display:flex as well; without it every slot shows empty (the first rule above does not reach them).
        $this->assertMatchesRegularExpression('/\.doc-notice \[hidden\][^{}]*\{\s*display:\s*none;/', $css);

        // The one sentence in the strip that Alpine writes (a refused AI
        // result or suggestion) was emptied by the same morph. It is
        // skipped by the morph as well.
        $aiError = $strip->querySelector('[x-show="aiError"]');
        $this->assertNotNull($aiError);
        $this->assertTrue($aiError->hasAttribute('wire:ignore'));
        $this->assertNotNull($aiError->querySelector('[x-text="aiError"]'));
    }

    /**
     * The strip's Saved / Ready word is whatever the server last rendered,
     * so it said Saved whenever the writer paused, whatever the page knew:
     * after a save that failed or never answered, and for the moment
     * between the end of typing and the autosave. `unsaved` is the page's
     * own knowledge that the editor holds text no accepted save has stored
     * (resources/js/editor/sync/host.js), and the word is not shown while
     * it is true.
     *
     * The condition sits on the wrapper, which is rendered the same on every
     * render, and uses x-show, the one binding a morph leaves alone on the
     * live element: the word inside is server-rendered and comes and goes,
     * so this wrapper cannot be `wire:ignore` the way the notice bar is.
     */
    public function test_the_strip_does_not_say_saved_while_the_page_holds_unsaved_text(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $strip = $dom->querySelector('.doc-status');
        $this->assertNotNull($strip);

        $word = $strip->querySelector('.status-word-good');
        $this->assertNotNull($word, 'a freshly opened page renders the Saved / Ready word');
        $this->assertContains(trim($word->textContent), ['Saved', 'Ready']);
        $this->assertFalse($word->hasAttribute('x-show'), 'the condition is on the wrapper, not on the word');

        $wrapper = $word->parentElement;
        $this->assertNotNull($wrapper);
        $this->assertNotSame($strip, $wrapper);
        $this->assertSame(
            '!isTyping && !isOffline && !conflict && !syncNotice && !unsaved',
            $wrapper->getAttribute('x-show'),
        );
        $this->assertFalse($wrapper->hasAttribute('wire:ignore'));

        // And the flag it reads is a field of the page's own state.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertMatchesRegularExpression('/^\s*unsaved: false,$/m', $alpine[1]);
    }

    /**
     * The merge (resources/js/editor/sync/host.js, Phase 2) writes eight more
     * state fields (overwriteFloor: the base an owed overwrite first became
     * owed on, which the draft is stamped below). Like every other, each is
     * declared in the component's own object, never in the module.
     */
    public function test_the_page_declares_the_merge_state_fields_the_host_writes(): void
    {
        $html = $this->editorHtml();

        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        foreach (['merging: null,', 'mergeAttempts: 0,', 'known: null,', 'sentSnapshot: null,', 'unanswered: null,', 'lost: [],', 'putBackSeq: 0,', 'overwriteFloor: null,', 'parkOfferPending: false,'] as $field) {
            $this->assertMatchesRegularExpression('/^\s*'.preg_quote($field, '/').'$/m', $alpine[1], "{$field} is declared");
        }
    }

    /**
     * The strip has two ways of saying Not saved: the Alpine word for a
     * standing conflict (`x-show="conflict"`) and the server's word for a
     * rejected save (`@error('content')`). With a conflict standing AND a
     * rejected save they stood side by side. The conflict word follows the
     * Saved / Ready pattern: a wrapper that is rendered on every render
     * carries the x-show, and the word inside it is rendered only while no
     * content error says Not saved already.
     */
    public function test_the_strip_never_says_not_saved_twice(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Wired');
        $component = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        // A rejected save: the server's word says Not saved, and it is the
        // only one in the strip (the conflict word is Alpine-shown, so in
        // a browser it would stand beside the error whenever a conflict
        // stood too).
        $component->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]], 1)
            ->assertHasErrors('content');
        $strip = HTMLDocument::createFromString($component->html(), LIBXML_NOERROR)->querySelector('.doc-status');
        $this->assertNotNull($strip);
        $this->assertSame(1, substr_count($strip->textContent, 'Not saved'), 'Not saved is said once');
        $wrapper = $strip->querySelector('[x-show="conflict"]');
        $this->assertNotNull($wrapper, 'the wrapper is rendered on every render');
        $this->assertNull($wrapper->querySelector('.status-word'), 'and holds no word while the content error says Not saved');
        $this->assertSame('', $this->words($wrapper));

        // No error: the wrapper holds the word.
        $component->call('saveContent', $this->para('Typed'), 1)
            ->assertHasNoErrors('content');
        $strip = HTMLDocument::createFromString($component->html(), LIBXML_NOERROR)->querySelector('.doc-status');
        $this->assertNotNull($strip);
        $wrapper = $strip->querySelector('[x-show="conflict"]');
        $this->assertNotNull($wrapper);
        $this->assertStringNotContainsString('status-word', (string) $wrapper->getAttribute('class'), 'the condition is on a wrapper, not on the word');
        $this->assertNotNull($wrapper->querySelector('.status-word-danger'));
        $this->assertSame('Not saved', $this->words($wrapper));
    }

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    /**
     * Put it back replaces the whole document on the page, and through the
     * save that follows the stored one, with an older whole copy. The row
     * used to be one unexplained button under "Your text was set aside.",
     * shown also for a draft whose text was already in the document, and it
     * could not be declined. Now the row says what it is, per source, and
     * what the button does, and it has a second button that declines.
     *
     * The source is `setAsideFrom` (resources/js/editor/sync/host.js):
     * 'conflict' is text this tab set aside a moment ago through Load
     * theirs; anything else is a draft from an earlier visit that the
     * document has moved past, which the page did not take away and which
     * may already be part of the document. putBack() asks first in exactly
     * the cases the first sentence does not cover.
     */
    public function test_the_set_aside_notice_says_what_it_is_and_what_its_buttons_do_and_can_be_declined(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $bar = $dom->querySelector('.doc-notices');
        $this->assertNotNull($bar);
        $row = $this->notice($bar, 'setAside');

        // One sentence per source, and never both: each is hidden in the
        // markup and switched by the source.
        $sentences = [];
        foreach ($row->querySelectorAll('.doc-notice-text > span[x-bind\:hidden]') as $sentence) {
            $this->assertTrue($sentence->hasAttribute('hidden'));
            $sentences[$sentence->getAttribute('x-bind:hidden')] = $this->words($sentence);
        }
        $this->assertSame([
            "setAsideFrom !== 'conflict'" => 'Your text was set aside. Put it back replaces what is on the page now; the version it replaces is kept in the history.',
            "setAsideFrom === 'conflict'" => 'A draft from your last visit here differs from the document as it is now. It may already be part of it.',
        ], $sentences);

        // Two buttons, in this order.
        $buttons = [];
        foreach ($row->querySelectorAll('button') as $button) {
            $this->assertSame('button', $button->getAttribute('type'));
            $buttons[$this->words($button)] = [$button->getAttribute('@click'), $button->getAttribute('title')];
        }
        $this->assertSame([
            'Put it back' => [
                'putBack()',
                'Replace what is on the page now with this text. The version it replaces is kept in the history.',
            ],
            'Discard' => [
                'discardSetAside()',
                'Remove this notice and leave the page as it is. Text set aside by Load theirs is in no saved version, so you are asked first; it is removed from this page and kept only in this browser, for 7 days, where this browser keeps drafts.',
            ],
        ], $buttons);

        // The page's state carries the source and the version the text was
        // set aside against, and Discard hands over to the module.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertMatchesRegularExpression('/^\s*setAsideFrom: null,$/m', $alpine[1]);
        $this->assertMatchesRegularExpression('/^\s*setAsideBase: null,$/m', $alpine[1]);
        $this->assertStringContainsString('discardSetAside() { this.syncHost().discardSetAside(); }', $alpine[1]);
    }

    /**
     * What each choice does to somebody's text used to be said only in a
     * `title`, which a keyboard does not bring up and screen readers treat
     * unevenly. Each button is now also described by an element in the bar
     * (aria-describedby) that says the same as its title.
     *
     * And a button pressed from the keyboard hands the keyboard back to the
     * document: the row that held the button goes away once the choice is
     * carried out, and the focus was left on nothing.
     */
    public function test_the_notice_buttons_say_what_they_do_and_give_the_keyboard_back(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $bar = $dom->querySelector('.doc-notices');
        $this->assertNotNull($bar);

        $described = 0;
        foreach ($bar->querySelectorAll('button') as $button) {
            $id = (string) $button->getAttribute('aria-describedby');
            $this->assertNotSame('', $id, $this->words($button).' is described');
            $this->assertCount(1, $dom->querySelectorAll('[id="'.$id.'"]'));
            $description = $bar->querySelector('[id="'.$id.'"]');
            $this->assertNotNull($description, 'the description is inside the bar');
            $this->assertTrue($description->hasAttribute('hidden'), 'it is read with the button, not shown');
            $this->assertSame($button->getAttribute('title'), $this->words($description));
            $described++;
        }
        $this->assertSame(11, $described, 'Keep mine, Load theirs, five Put it back, Put them all back, Dismiss all, Put it back, Discard');

        // One listener on the bar, after the button's own; the method is
        // page glue in the component.
        $this->assertSame('noticePressed($event)', $bar->getAttribute('@click'));
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertStringContainsString('noticePressed(event) {', $alpine[1]);
    }

    /**
     * The lost row (Phase 2, section 6 of the spec): what a merge could not
     * keep, offered back item by item, each with its reason, its first 80
     * characters and its own Put it back. Five static item slots switched
     * with the hidden attribute, never x-for: PHPUnit can pin every element,
     * and no Alpine pattern new to the bar is introduced. LOST_ROWS in
     * sync/host.js is the same five.
     */
    public function test_the_lost_row_offers_five_items_back_and_a_way_to_take_them_all_or_none(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $bar = $dom->querySelector('.doc-notices');
        $this->assertNotNull($bar);
        $row = $this->notice($bar, 'lost.length');

        $this->assertSame(
            'Not everything you typed could be kept. Each item below stays here until you put it back or dismiss it.',
            $this->words($row->querySelector('.doc-notice-text')),
        );

        $items = $row->querySelectorAll('.doc-notice-list > .doc-notice-item');
        $this->assertCount(6, $items, 'five slots and the overflow line');
        foreach (range(0, 4) as $i) {
            $item = $items->item($i);
            $this->assertTrue($item->hasAttribute('hidden'));
            $this->assertSame("!lost[{$i}]", $item->getAttribute('x-bind:hidden'));
            $this->assertSame("lost[{$i}] ? lost[{$i}].sentence : ''", $item->querySelector('span[x-text]')->getAttribute('x-text'));
            $this->assertSame("lost[{$i}] ? lost[{$i}].excerpt : ''", $item->querySelector('q.doc-notice-excerpt')->getAttribute('x-text'));
            $button = $item->querySelector('button');
            $this->assertSame("putBackLost({$i})", $button->getAttribute('@click'));
            $this->assertSame('Put it back', $this->words($button));
            $this->assertSame('doc-notice-put-back-lost-does', $button->getAttribute('aria-describedby'));
        }
        $overflow = $items->item(5);
        $this->assertTrue($overflow->hasAttribute('hidden'));
        $this->assertCount(1, $overflow->children, 'the sentence is one flex item, not three');
        $this->assertSame('lost.length <= 5', $overflow->getAttribute('x-bind:hidden'));
        $this->assertSame('lost.length - 5', $overflow->querySelector('span[x-text]')->getAttribute('x-text'));
        $this->assertSame('And more. Put them all back restores every item, these included.', $this->words($overflow), 'the number is written by x-text, so it is empty here');

        $actions = [];
        foreach ($row->querySelectorAll('.doc-notice-actions > button') as $button) {
            $actions[$this->words($button)] = $button->getAttribute('@click');
        }
        $this->assertSame(['Put them all back' => 'putBackAllLost()', 'Dismiss all' => 'dismissLost()'], $actions);

        // Static slots, never x-for.
        $this->assertCount(0, $bar->querySelectorAll('template'));
        $this->assertStringNotContainsString('x-for', (string) $bar->innerHTML);

        // The five is one number, in the module too.
        $this->assertMatchesRegularExpression('/^export const LOST_ROWS = 5;$/m', file_get_contents(resource_path('js/editor/sync/host.js')));

        // The three delegates hand over to the module.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertStringContainsString('putBackLost(index) { this.syncHost().putBackLost(index); }', $alpine[1]);
        $this->assertStringContainsString('putBackAllLost() { this.syncHost().putBackAllLost(); }', $alpine[1]);
        $this->assertStringContainsString('dismissLost() { this.syncHost().dismissLost(); }', $alpine[1]);

        // The row's own styles, beside the bar's, and the excerpt's ink in the contrast gate.
        $css = file_get_contents(resource_path('css/shell.css'));
        foreach (['.doc-notice-list', '.doc-notice-item', '.doc-notice-excerpt'] as $selector) {
            $this->assertStringContainsString($selector.' {', $css, "{$selector} is styled");
        }
        $this->assertMatchesRegularExpression('/\.doc-notice-list \{[^}]*max-height:/', $css, 'the list is bounded so the sticky bar cannot outgrow the viewport');
        $this->assertStringContainsString("'.doc-notice-excerpt',", file_get_contents(base_path('scripts/design/contrast-dom.mjs')));
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
