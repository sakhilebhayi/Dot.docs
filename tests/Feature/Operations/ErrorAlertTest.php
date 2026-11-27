<?php

namespace Tests\Feature\Operations;

use App\Services\Guardian\ErrorAlert;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** R2-2 / O-06: an error the app reports is mailed to a person, without flooding them. */
class ErrorAlertTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'guardian.alert_email' => 'ops@example.com']);
        Event::listen(MessageSending::class, fn (MessageSending $e) => $this->sent[] = $e->message);
    }

    public function test_an_error_in_a_request_is_mailed_after_the_response_without_request_data(): void
    {
        Route::get('/__boom', fn () => throw new \RuntimeException('Database said no: secret-token-123 '.str_repeat('x', 400)));

        $this->withHeader('X-Secret', 'do-not-send')->get('/__boom?password=hunter2')->assertStatus(500);

        $this->assertCount(1, $this->sent);
        $mail = $this->sent[0];
        $this->assertSame('ops@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringStartsWith('[Dot.docs] RuntimeException in ', $mail->getSubject());
        $body = $mail->getTextBody();
        $this->assertStringContainsString('During: GET /__boom', $body);
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('do-not-send', $body);
        $this->assertLessThan(1300, strlen($body), 'the message is shortened');
    }

    public function test_the_same_error_is_mailed_once_an_hour_and_a_storm_is_capped(): void
    {
        $alert = app(ErrorAlert::class);

        $same = new \LogicException('again');
        for ($i = 0; $i < 5; $i++) {
            $alert->send($same);
        }

        // Many distinct errors in one hour (each thrown from a different line): capped.
        $made = array_map(fn (int $i) => $this->thrownAt($i), range(1, 30));
        foreach ($made as $e) {
            $alert->send($e);
        }
        app()->terminate();

        // terminate() once: it re-runs every after-response callback each time it is called.
        $subjects = array_map(fn ($mail) => $mail->getSubject(), $this->sent);
        $this->assertCount(1, array_filter($subjects, fn ($s) => str_contains($s, 'LogicException')), 'the same error, once');
        $this->assertSame(ErrorAlert::MAX_PER_HOUR, count($this->sent), 'the storm is capped');
    }

    public function test_a_database_errors_values_never_reach_the_mail(): void
    {
        $e = new QueryException('sqlite', 'select * from users where email = ?', ['someone@private.example'], new \Exception('boom'));

        app(ErrorAlert::class)->send($e);
        app()->terminate();

        $body = $this->sent[0]->getTextBody();
        $this->assertStringContainsString('select * from users where email = ?', $body);
        $this->assertStringNotContainsString('someone@private.example', $body);
    }

    /** An exception whose reported line differs per $i, so each one is a distinct error. */
    private function thrownAt(int $i): \Throwable
    {
        $e = new \DomainException("storm {$i}");
        (new \ReflectionProperty(\Exception::class, 'line'))->setValue($e, 1000 + $i);

        return $e;
    }

    public function test_nothing_is_sent_without_an_address(): void
    {
        config(['guardian.alert_email' => null]);
        app(ErrorAlert::class)->send(new \RuntimeException('quiet'));
        app()->terminate();

        $this->assertSame([], $this->sent);
    }
}
