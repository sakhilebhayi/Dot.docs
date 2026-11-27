<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Laravel's StartSession, except that a route marked with QuietSession does not write the session back when the
 * request changed nothing in it (EA-9 / R2-9).
 *
 * Production keeps sessions in the same SQLite file as everything else (SESSION_DRIVER=database), and Laravel writes
 * the session row at the end of EVERY request, changed or not. The editor's sync poll (POST /documents/{uuid}/sync)
 * runs every second or two per open tab while somebody else is present, so each poll took SQLite's single write lock
 * just to rewrite an identical payload and a new `last_activity`. On a quiet route that write is skipped when:
 *
 *  - the attributes at the end of the request are identical to those read at its start (a login, a regenerated
 *    token, AuthenticateSession storing the password hash, anything put() - all still saved),
 *  - no flash data is waiting to be aged (aging it is itself a change), and
 *  - this session was written by a quiet route less than REFRESH_SECONDS ago.
 *
 * The last condition keeps the session alive: `last_activity` is what the database handler expires a session by
 * (SESSION_LIFETIME minutes), so a tab that does nothing but poll still writes once every REFRESH_SECONDS, exactly as
 * it kept the session alive before. Auth and CSRF only READ the session, so they are unaffected; a request that fails
 * before the route's middleware runs (an expired session's 419, an unauthenticated 401) was never marked and saves as
 * it always did.
 */
class StartSession extends BaseStartSession
{
    /** The request attribute QuietSession sets on the routes allowed to skip an unchanged write. */
    public const QUIET = 'session.quiet';

    /** The session attribute holding when a quiet route last wrote this session (Unix seconds). */
    public const WRITTEN_AT = '_quiet_written_at';

    /** How often a quiet route still writes an unchanged session, to keep `last_activity` fresh. */
    public const REFRESH_SECONDS = 300;

    private const SNAPSHOT = 'session.snapshot';

    protected function startSession(Request $request, $session)
    {
        $session = parent::startSession($request, $session);

        // PHP arrays are copied on write, so holding this costs nothing until the session is changed.
        $request->attributes->set(self::SNAPSHOT, $session->all());

        return $session;
    }

    protected function saveSession($request)
    {
        if ($request->attributes->get(self::QUIET) === true && $request->hasSession()) {
            $session = $request->session();
            $unchanged = $session->all() === $request->attributes->get(self::SNAPSHOT);
            $noFlash = $session->get('_flash.new', []) === [] && $session->get('_flash.old', []) === [];
            $writtenAt = $session->get(self::WRITTEN_AT);
            $fresh = is_int($writtenAt) && now()->getTimestamp() - $writtenAt < self::REFRESH_SECONDS;

            if ($unchanged && $noFlash && $fresh) {
                return;
            }

            $session->put(self::WRITTEN_AT, now()->getTimestamp());
        }

        parent::saveSession($request);
    }
}
