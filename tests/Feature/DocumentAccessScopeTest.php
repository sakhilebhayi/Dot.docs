<?php

namespace Tests\Feature;

use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dot.Finance (HasUserScope) and Dot.Notify (HasTeamScope) both apply a
 * single-column Eloquent global scope so a forgotten where() can never leak
 * another tenant's rows. Dot.Doc was evaluated for the same pattern and
 * deliberately did NOT get it: `team_id` is nullable on every candidate
 * model (documents, document_templates, document_slash_commands) and real
 * access is multi-path — owner OR team OR named collaborator OR public
 * share OR (for templates) is_global — not a single tenant column. A blind
 * where('team_id', currentTeam) global scope would silently break that:
 * every personal (team_id null) document would vanish from its own owner's
 * queries, and every is_global template would vanish for everyone. See
 * wiki.md's Change Log entry for this pass for the full reasoning.
 *
 * The real isolation boundary here is DocumentPolicy (already correct, see
 * wiki.md §6), not a model-level scope. This test proves that boundary
 * holds for the two concrete cases a naive global scope would have gotten
 * wrong: a personal, team-less document, and a private (non-global)
 * template.
 */
class DocumentAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_policy_blocks_cross_tenant_access_even_for_a_teamless_personal_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => "Owner's Personal Doc",
            'content' => 'private content',
            'owner_id' => $owner->id,
            'team_id' => null,
            'version' => 1,
            'is_public' => false,
        ]);

        // The row itself is not hidden by any global scope (there isn't
        // one) — it's DocumentPolicy that must gate it.
        $this->assertNotNull(Document::find($document->id));

        $this->actingAs($outsider);
        Livewire::test(Editor::class, ['uuid' => $document->uuid])
            ->assertForbidden();
    }

    public function test_owner_retains_access_to_their_own_teamless_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => "Owner's Personal Doc",
            'content' => 'private content',
            'owner_id' => $owner->id,
            'team_id' => null,
            'version' => 1,
            'is_public' => false,
        ]);

        $this->actingAs($owner);
        Livewire::test(Editor::class, ['uuid' => $document->uuid])
            ->assertOk();
    }

    public function test_private_team_template_is_invisible_to_an_outside_team_but_a_global_template_is_not(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $private = DocumentTemplate::create([
            'name' => 'Team-only template',
            'category' => 'general',
            'content' => 'template body',
            'is_global' => false,
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $global = DocumentTemplate::create([
            'name' => 'Global template',
            'category' => 'general',
            'content' => 'template body',
            'is_global' => true,
            'team_id' => null,
            'created_by' => $owner->id,
        ]);

        // Same visibility rule TemplateGallery::templates()/useTemplate() apply:
        // is_global OR own team OR own authorship.
        $visibleToOutsider = DocumentTemplate::query()
            ->where(function ($q) use ($outsider) {
                $q->where('is_global', true)
                    ->orWhere('created_by', $outsider->id);
                if ($outsider->currentTeam) {
                    $q->orWhere('team_id', $outsider->currentTeam->id);
                }
            })
            ->pluck('id');

        $this->assertTrue($visibleToOutsider->contains($global->id));
        $this->assertFalse($visibleToOutsider->contains($private->id));
    }
}
