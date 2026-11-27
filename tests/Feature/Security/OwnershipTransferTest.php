<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentStore;
use App\Livewire\Documents\DocumentSettings;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-1 / S-12: a document is handed over only inside its company, and every handover is on the record. */
class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Document} owner, a viewer-role colleague, a company document */
    private function company(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $colleague = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($colleague, ['role' => 'viewer']);

        return [$owner, $colleague, app(DocumentStore::class)->create($owner, 'Company plan')];
    }

    public function test_it_hands_the_document_to_a_colleague_and_records_it(): void
    {
        [$owner, $colleague, $doc] = $this->company();

        Livewire::actingAs($owner)->test(DocumentSettings::class, ['uuid' => $doc->uuid])
            ->set('transferEmail', $colleague->email)
            ->call('transferOwnership')
            ->assertHasNoErrors();

        $this->assertSame($colleague->id, $doc->fresh()->owner_id);
        $row = AuditLog::where('action', 'document.owner_transferred')->sole();
        $this->assertSame(['reason' => 'handed_over', 'from_user_id' => $owner->id, 'to_user_id' => $colleague->id], $row->context);
        $this->assertSame($owner->id, $row->actor_id);
    }

    public function test_someone_outside_the_company_is_refused_with_the_same_words_as_no_account(): void
    {
        [$owner, , $doc] = $this->company();
        $outsider = User::factory()->withPersonalTeam()->create();

        $refusals = [];
        foreach ([$outsider->email, 'nobody-at-all@example.com'] as $email) {
            $component = Livewire::actingAs($owner)->test(DocumentSettings::class, ['uuid' => $doc->uuid])
                ->set('transferEmail', $email)
                ->call('transferOwnership')
                ->assertHasErrors('transferEmail');
            $refusals[] = $component->errors()->first('transferEmail');
        }

        $this->assertSame($refusals[0], $refusals[1]);
        $this->assertSame($owner->id, $doc->fresh()->owner_id);
        $this->assertSame(0, AuditLog::where('action', 'document.owner_transferred')->count());
    }

    public function test_the_previous_owner_keeps_editing_even_when_their_role_is_viewer(): void
    {
        [$owner, $colleague, $doc] = $this->company();
        // The colleague takes it, then hands it back: the colleague is only a viewer in the company.
        $doc->forceFill(['owner_id' => $colleague->id])->save();

        Livewire::actingAs($colleague)->test(DocumentSettings::class, ['uuid' => $doc->uuid])
            ->set('transferEmail', $owner->email)
            ->call('transferOwnership')
            ->assertHasNoErrors();

        $this->assertSame($owner->id, $doc->fresh()->owner_id);
        $this->assertTrue($colleague->fresh()->can('update', $doc->fresh()));
        $this->assertSame('editor', $doc->collaborators()->where('user_id', $colleague->id)->value('role'));
    }

    public function test_handing_it_to_its_own_owner_is_refused(): void
    {
        [$owner, , $doc] = $this->company();

        Livewire::actingAs($owner)->test(DocumentSettings::class, ['uuid' => $doc->uuid])
            ->set('transferEmail', $owner->email)
            ->call('transferOwnership')
            ->assertHasErrors('transferEmail');
    }
}
