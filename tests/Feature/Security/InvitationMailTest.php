<?php

namespace Tests\Feature\Security;

use App\Actions\Jetstream\InviteTeamMember;
use App\Mail\QueuedTeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * EA-1 / E-01: a team's name is typed by its owner and used to go into the
 * invitation mail as Markdown, so `[Verify your account](https://evil.test)`
 * arrived as a live link in mail sent from this app's domain.
 */
class InvitationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_name_cannot_put_a_link_or_markup_into_the_invitation(): void
    {
        Mail::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->forceFill(['name' => '[Verify your account](https://evil.test/x) <b>now</b>'])->save();

        app(InviteTeamMember::class)->invite($owner, $team->fresh(), 'someone@example.com', 'editor');

        Mail::assertQueued(QueuedTeamInvitation::class, function (QueuedTeamInvitation $mail) {
            $html = $mail->render();

            $this->assertStringNotContainsString('href="https://evil.test', $html);
            $this->assertStringNotContainsString('<b>now</b>', $html);
            // The words are still there for the reader, shown literally.
            $this->assertStringContainsString('Verify your account', html_entity_decode($html));

            return true;
        });
    }

    public function test_invitations_are_limited_per_inviter_per_hour(): void
    {
        Mail::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $action = app(InviteTeamMember::class);

        for ($i = 0; $i < InviteTeamMember::PER_HOUR; $i++) {
            $action->invite($owner, $team, "person{$i}@example.com", 'editor');
        }

        $this->expectException(ValidationException::class);
        $action->invite($owner, $team, 'one-too-many@example.com', 'editor');
    }

    public function test_invitations_are_limited_per_team_per_hour(): void
    {
        Mail::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        RateLimiter::increment('team-invite-team:'.$team->id, 3600, InviteTeamMember::PER_TEAM_PER_HOUR);

        try {
            app(InviteTeamMember::class)->invite($owner, $team, 'someone@example.com', 'editor');
            $this->fail('The invitation was not refused.');
        } catch (ValidationException) {
            Mail::assertNothingQueued();
            $this->assertSame(0, $team->teamInvitations()->count());
        }
    }
}
