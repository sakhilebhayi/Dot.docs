<?php

namespace Tests\Feature\Mail;

use App\Jobs\SendSsoClaimCode;
use App\Mail\Delivery\MailDeliveryListener;
use App\Mail\QueuedTeamInvitation;
use App\Mail\SsoClaimCodeMail;
use App\Models\User;
use App\Notifications\Account\PasswordChanged;
use App\Notifications\CommentEmailNotification;
use App\Sso\DocsProvisioner;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** R2-4: email reliability - the claim code queued (E-05), each email sent once (E-10), a give-up reaches a person (E-02). */
class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Email> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
        // Registered after the app's own listeners, so it only sees a message the ledger let through.
        Event::listen(MessageSending::class, function (MessageSending $e) {
            $this->sent[] = $e->message;
        });
    }

    public function test_the_claim_code_is_queued_on_the_mail_queue_and_never_readable_in_the_jobs_table(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

        app(DocsProvisioner::class)->sendClaimCode($user, '913577', 10);

        $this->assertSame([], $this->sent, 'nothing is sent inside the request');
        $job = DB::table('jobs')->sole();
        $this->assertSame('mail', $job->queue);
        $this->assertStringNotContainsString('913577', $job->payload);
        $this->assertStringNotContainsString('ada@example.test', $job->payload);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendSsoClaimCode(1, 'a@b.test', 'Ada', '1', 10));
        $this->liveCode($user, '913577');

        $this->artisan('queue:work', ['--queue' => 'mail', '--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('913577', $this->sent[0]->getTextBody());
        $this->assertSame(1, DB::table('sent_mails')->where('kind', SsoClaimCodeMail::class)->count());
    }

    public function test_a_retry_sends_only_a_code_that_is_still_live(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $old = new SendSsoClaimCode($user->id, $user->email, 'Ada', '111111', 10);
        $this->liveCode($user, '111111');

        // The person asked again before the retry ran: a new code replaces the old one.
        DB::table('dotsso_claims')->delete();
        $this->liveCode($user, '222222');
        $old->handle();
        $this->assertSame([], $this->sent, 'the replaced code is not sent');

        (new SendSsoClaimCode($user->id, $user->email, 'Ada', '222222', 10))->handle();
        $this->assertCount(1, $this->sent);

        // An expired code is not sent either.
        DB::table('dotsso_claims')->update(['expires_at' => now()->subMinute()]);
        (new SendSsoClaimCode($user->id, $user->email, 'Ada', '222222', 10))->handle();
        $this->assertCount(1, $this->sent);
    }

    /** A claim row the way the dot-sso-client package writes it. */
    private function liveCode(User $user, string $code): void
    {
        $claimId = 'claim-'.$code;
        DB::table('dotsso_claims')->insert([
            'claim_id' => $claimId,
            'user_key' => (string) $user->id,
            'email' => $user->email,
            'sub' => 'infodot-uuid',
            'code_hash' => hash_hmac('sha256', $claimId.':'.$code, (string) config('app.key')),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_retried_mailable_whose_email_already_went_is_not_sent_again(): void
    {
        $mail = new SsoClaimCodeMail('Ada', '913577', 10);
        // The job is retried as the same serialized mailable, carrying the same reference.
        $retry = unserialize(serialize($mail));

        Mail::to('ada@example.test')->send($mail);
        Mail::to('ada@example.test')->send($retry);

        $this->assertCount(1, $this->sent);
        $this->assertSame($mail->deliveryRef(), $retry->deliveryRef());
        $this->assertSame($mail->deliveryRef(), $this->sent[0]->getHeaders()->get(MailDeliveryListener::REF_HEADER)?->getBodyAsString());

        // A new email (a new code) is a new reference, and goes.
        Mail::to('ada@example.test')->send(new SsoClaimCodeMail('Ada', '204466', 10));
        $this->assertCount(2, $this->sent);
    }

    public function test_a_retried_notification_whose_email_already_went_is_not_sent_again(): void
    {
        $user = User::factory()->create();
        $notice = new PasswordChanged;
        // Laravel gives a queued notification its id once, when it is queued; every retry has it.
        $notice->id = 'a5e3b1c8-0000-4000-8000-000000000001';

        $user->notifyNow($notice);
        $user->notifyNow(clone $notice);

        $this->assertCount(1, $this->sent);
        $this->assertDatabaseHas('sent_mails', ['ref' => 'n:'.$notice->id, 'kind' => PasswordChanged::class]);

        $user->notifyNow(new PasswordChanged);
        $this->assertCount(2, $this->sent, 'another notice is another email');
    }

    public function test_the_comment_mail_cap_counts_emails_that_went_not_attempts(): void
    {
        $user = User::factory()->create();
        $key = 'comment-email:'.$user->id;
        $notice = new class extends CommentEmailNotification
        {
            public function __construct() {}

            protected function bellNotification(): string
            {
                return PasswordChanged::class;
            }

            protected function mayReceive(User $user): bool
            {
                return true;
            }
        };

        // Checks alone, however many, use none of the thirty.
        for ($i = 0; $i < 40; $i++) {
            RateLimiter::tooManyAttempts($key, 30);
        }
        $this->assertSame(0, RateLimiter::attempts($key));

        $notice->countAgainstCap($user);
        $this->assertSame(1, RateLimiter::attempts($key));

        for ($i = 0; $i < 29; $i++) {
            $notice->countAgainstCap($user);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 30), 'thirty sent: the thirty-first waits');
    }

    public function test_an_email_given_up_on_is_mailed_to_a_person_without_the_address(): void
    {
        config(['guardian.alert_email' => 'ops@example.com']);

        (new PasswordChanged)->failed(new TransportException('550 5.1.1 <ada@example.test>: Recipient address rejected'));
        app()->terminate();

        $this->assertCount(1, $this->sent);
        $alert = $this->sent[0];
        $this->assertSame('ops@example.com', $alert->getTo()[0]->getAddress());
        $this->assertStringContainsString('MailNotDelivered', (string) $alert->getSubject());
        $body = (string) $alert->getTextBody();
        $this->assertStringContainsString('"PasswordChanged" email was given up', $body);
        $this->assertStringContainsString('queue:retry', $body);
        $this->assertStringNotContainsString('ada@example.test', $body);
    }

    public function test_each_kind_of_email_given_up_on_is_its_own_alert_and_a_repeat_is_not(): void
    {
        config(['guardian.alert_email' => 'ops@example.com']);

        $reason = new TransportException('connection refused');
        (new PasswordChanged)->failed($reason);
        (new PasswordChanged)->failed($reason);
        (new SendSsoClaimCode(1, 'a@b.test', 'Ada', '1', 10))->failed($reason);
        app()->terminate();

        $this->assertCount(2, $this->sent);
    }

    public function test_an_address_in_any_reported_error_is_masked_in_the_alert(): void
    {
        config(['guardian.alert_email' => 'ops@example.com']);

        report(new \RuntimeException('Could not deliver to first.last+tag@mail.example.co.za today'));
        app()->terminate();

        $body = (string) $this->sent[0]->getTextBody();
        $this->assertStringContainsString('Could not deliver to [address] today', $body);
    }

    public function test_a_retried_team_invitation_is_sent_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $invitation = $owner->currentTeam->teamInvitations()->create(['email' => 'new@example.test', 'role' => 'editor']);

        $mail = new QueuedTeamInvitation($invitation);
        $this->assertNotNull($mail->deliveryRef, 'made with the mailable, before it is queued');
        $retry = unserialize(serialize($mail));

        Mail::to('new@example.test')->send($mail);
        Mail::to('new@example.test')->send($retry);

        $this->assertCount(1, $this->sent);
        $this->assertDatabaseHas('sent_mails', ['ref' => $mail->deliveryRef, 'kind' => QueuedTeamInvitation::class]);
    }
}
