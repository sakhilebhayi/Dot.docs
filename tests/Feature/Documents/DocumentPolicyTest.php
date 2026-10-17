<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may do what to a document that belongs to a team.
 *
 * Grants are a UNION: the document's owner, the team (its owner and its
 * admins count as admins; editors edit and share; viewers read) and the
 * document's own collaborator rows each add to what a person may do, and
 * none of them takes anything away. A team viewer who is an editor
 * collaborator on this one document may edit it. Delete and manage stay with
 * the document's owner and the team's admins; a collaborator never gets
 * them. A person with no grant at all is refused, as before.
 */
class DocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: array<string, User>, 1: Document} */
    private function people(bool $public = false): array
    {
        $teamOwner = User::factory()->withPersonalTeam()->create();
        $team = $teamOwner->currentTeam;
        $docOwner = User::factory()->withPersonalTeam()->create();

        $member = function (string $role) use ($team) {
            $user = User::factory()->withPersonalTeam()->create();
            $team->users()->attach($user, ['role' => $role]);

            return $user;
        };

        $people = [
            'document owner' => $docOwner,
            'team owner' => $teamOwner,
            'team admin' => $member('admin'),
            'team editor' => $member('editor'),
            'team viewer' => $member('viewer'),
            'team viewer + editor collaborator' => $member('viewer'),
            'team viewer + viewer collaborator' => $member('viewer'),
            'team editor + viewer collaborator' => $member('editor'),
            'collaborator editor (not in team)' => User::factory()->withPersonalTeam()->create(),
            'collaborator viewer (not in team)' => User::factory()->withPersonalTeam()->create(),
            'outsider' => User::factory()->withPersonalTeam()->create(),
        ];

        $doc = new Document(['title' => 'Team doc', 'owner_id' => $docOwner->id, 'team_id' => $team->id, 'version' => 1, 'style_key' => 'report']);
        $doc->is_public = $public;
        $doc->save();

        foreach ([
            'team viewer + editor collaborator' => 'editor',
            'team viewer + viewer collaborator' => 'viewer',
            'team editor + viewer collaborator' => 'viewer',
            'collaborator editor (not in team)' => 'editor',
            'collaborator viewer (not in team)' => 'viewer',
        ] as $who => $role) {
            DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $people[$who]->id, 'role' => $role]);
        }

        return [$people, $doc];
    }

    /** @return array<string, array{0: string, 1: array<string, bool>}> */
    public static function abilities(): array
    {
        $names = [
            'document owner', 'team owner', 'team admin', 'team editor', 'team viewer', 'team viewer + editor collaborator',
            'team viewer + viewer collaborator', 'team editor + viewer collaborator', 'collaborator editor (not in team)',
            'collaborator viewer (not in team)', 'outsider',
        ];
        $row = fn (array $allowed) => array_combine($names, array_map(fn ($n) => in_array($n, $allowed, true), $names));

        $readers = array_values(array_diff($names, ['outsider']));
        $writers = ['document owner', 'team owner', 'team admin', 'team editor', 'team viewer + editor collaborator', 'team editor + viewer collaborator', 'collaborator editor (not in team)'];
        $managers = ['document owner', 'team owner', 'team admin'];

        return [
            'view' => ['view', $row($readers)],
            'update' => ['update', $row($writers)],
            'share' => ['share', $row($writers)],
            'delete' => ['delete', $row($managers)],
            'manage' => ['manage', $row($managers)],
        ];
    }

    #[DataProvider('abilities')]
    public function test_each_person_may_do_exactly_what_their_grants_add_up_to(string $ability, array $expected): void
    {
        [$people, $doc] = $this->people();

        foreach ($expected as $who => $allowed) {
            $user = User::findOrFail($people[$who]->id);
            $this->assertSame($allowed, $user->can($ability, $doc->fresh()), "{$who} / {$ability}");
        }
    }

    public function test_on_a_public_document_a_stranger_may_read_and_nothing_else(): void
    {
        [$people, $doc] = $this->people(public: true);
        $outsider = User::findOrFail($people['outsider']->id);

        $this->assertTrue($outsider->can('view', $doc->fresh()));
        foreach (['update', 'share', 'delete', 'manage'] as $ability) {
            $this->assertFalse($outsider->can($ability, $doc->fresh()), $ability);
        }
    }

    public function test_a_document_with_no_team_is_unchanged(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $doc = new Document(['title' => 'Solo', 'owner_id' => $owner->id, 'version' => 1, 'style_key' => 'report']);
        $doc->save();

        $this->assertTrue($owner->can('update', $doc));
        $this->assertFalse($other->can('view', $doc));
        $this->assertFalse($other->can('update', $doc));

        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $other->id, 'role' => 'editor']);
        $this->assertTrue($other->fresh()->can('update', $doc->fresh()));
        $this->assertFalse($other->fresh()->can('delete', $doc->fresh()));
    }
}
