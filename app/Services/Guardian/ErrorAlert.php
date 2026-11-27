<?php

namespace App\Services\Guardian;

use App\Mail\Delivery\MailNotDelivered;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * An error the app reports reaches a person (R2-2 / O-06), not only the hourly counter Guardian reads.
 *
 * With OPS_ALERT_EMAIL set, each distinct error (its class and where it was thrown) is mailed at most once an hour,
 * and no more than MAX_PER_HOUR alerts go out in an hour whatever happens - a storm of errors sends a handful of mails,
 * not thousands. The mail is sent after the response (the visitor never waits for it) and carries no request data:
 * the error's class, a shortened message, file and line, the method and path, and how many errors this hour.
 *
 * Nothing here ever throws: alerting must not be able to break real error handling. When the cache itself is
 * unusable (CACHE_STORE=database and the database is the problem) no alert can be throttled, so none is sent - the
 * hourly counter, Guardian's checks and /up/scheduler still see it. Production needs a real mailer (MAIL_MAILER=smtp).
 */
class ErrorAlert
{
    public const MAX_PER_HOUR = 10;

    public function __construct(private ErrorCounter $counter) {}

    public function send(\Throwable $e): void
    {
        try {
            $to = (string) config('guardian.alert_email');
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            // A mail given up on is one alert per kind of email, not one for all of them (R2-4).
            $kind = $e instanceof MailNotDelivered ? $e->kind : '';
            $fingerprint = sha1($e::class.'|'.$e->getFile().'|'.$e->getLine().'|'.$kind);
            if (! Cache::add('guardian:alert:'.$fingerprint, 1, now()->addHour())) {
                return;
            }

            $hourKey = 'guardian:alerts:'.now()->format('Y-m-d-H');
            Cache::add($hourKey, 0, 7200);
            if (Cache::increment($hourKey) > self::MAX_PER_HOUR) {
                return;
            }

            $body = $this->body($e);
            $subject = '[Dot.docs] '.class_basename($e).' in '.basename($e->getFile()).':'.$e->getLine();

            dispatch(function () use ($to, $subject, $body) {
                try {
                    Mail::raw($body, fn ($message) => $message->to($to)->subject($subject));
                } catch (\Throwable $failure) {
                    Log::warning('error-alert mail failed', ['error' => $failure->getMessage()]);
                }
            })->afterResponse();
        } catch (\Throwable) {
            // Alerting must never interfere with real error handling.
        }
    }

    /**
     * The message, minus personal data: a database error's message carries the SQL WITH its bound values (emails,
     * tokens), so for those only the statement with its placeholders and the driver's error code are sent. Any email
     * address left in a message (a mail server's "550 <someone@example.com> rejected") is masked (R2-4).
     */
    private function message(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return 'database error '.(string) ($e->errorInfo[1] ?? $e->getCode()).' on: '.$e->getSql();
        }

        return (string) preg_replace('/[^\s<>"\'@]+@[^\s<>"\'@]+\.[a-z]{2,}/i', '[address]', $e->getMessage());
    }

    private function body(\Throwable $e): string
    {
        // A routed request (a page, an action); anything else is a command or a queued job.
        $request = app()->bound('request') ? request() : null;
        $where = $request !== null && $request->route() !== null
            ? $request->method().' /'.ltrim($request->path(), '/')
            : 'console / queue';

        return implode("\n", [
            'Dot.docs reported an error.',
            '',
            'When:   '.now()->toDateTimeString().' (server time)',
            'What:   '.$e::class,
            'Says:   '.Str::limit(str_replace(["\r", "\n"], ' ', $this->message($e)), 300),
            'Where:  '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
            'During: '.$where,
            'Errors reported this hour so far: '.$this->counter->countForHour(now()),
            '',
            'The same error is not mailed again for an hour, and at most '.self::MAX_PER_HOUR.' alerts go out an hour.',
            'Details are in storage/logs/laravel.log on the host.',
        ]);
    }
}
