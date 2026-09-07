<?php

namespace Tests\Feature\Styles;

use App\Documents\DocumentStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 1 finding: the editor view had TWO top-level elements —
 * <style id="doc-style"> before the root <div x-data=…>. Livewire attaches
 * wire:snapshot (and wire:id/wire:effects) to whichever element is FIRST in
 * the rendered HTML, so the bug put the Editor component's own hydration
 * state on the <style> tag instead of its <div>, and the whole editor lost
 * interactivity. This proves the fix: the Editor's snapshot lands on a
 * <div>, and <style id="doc-style"> renders as a plain, non-root element.
 */
class EditorStyleRootTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_style_tag_is_not_the_livewire_component_root(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');

        $html = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->getContent();

        // The Editor component's own snapshot is identifiable by its
        // contentJson property — the other Livewire components rendered on
        // this page (nav, notifications, the AI sidebars) carry unrelated
        // snapshots that don't have it.
        $pos = strpos($html, '&quot;contentJson&quot;');
        $this->assertNotFalse($pos, 'expected to find the Editor component snapshot in the page');

        $tagStart = strrpos(substr($html, 0, $pos), '<');
        preg_match('/<([a-zA-Z0-9]+)/', substr($html, $tagStart, 20), $tag);

        $this->assertSame(
            'div',
            $tag[1] ?? null,
            'Livewire attached the Editor snapshot to a <'.($tag[1] ?? '?').'> — the <style id="doc-style"> '.
            'tag must not be the first element rendered by the component, or Livewire hydrates it instead of the root <div>'
        );

        // The <style> tag itself must render as an ordinary, non-root
        // element: no Livewire attributes injected ahead of its own id.
        $this->assertStringContainsString('<style id="doc-style">', $html);
    }
}
