<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The append-only record of who did what to which document.
 *
 * This is an ACCESS log, not a content log: it answers "who read, exported,
 * shared or rolled back this document", the questions an enterprise audit
 * asks. Content writes are DocumentStore's job and versions are the record
 * of those - do not duplicate them here.
 *
 * Actions are dotted `subject.verb` strings, past tense, and the vocabulary
 * is closed (see .ai/rules/audit.md): document.viewed,
 * document.exported, share.updated, version.restored.
 */
class AuditLogger
{
    /**
     * @param  array<string,mixed>  $context  anything worth knowing about the
     *                                        event that is not the subject
     *                                        itself (an export format, the
     *                                        version number restored). Never
     *                                        document content, and never a
     *                                        secret - these rows are read by
     *                                        team admins.
     */
    public function record(string $action, Model $subject, array $context = [], ?User $actor = null): AuditLog
    {
        $actor ??= Auth::user();

        return AuditLog::create([
            'team_id' => $this->teamId($subject, $actor),
            'actor_type' => $actor ? 'user' : 'system',
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'context' => $context === [] ? null : $context,
            'ip' => $this->requestValue(fn ($request) => $request->ip()),
            'user_agent' => $this->clip($this->requestValue(fn ($request) => $request->userAgent()), 255),
            'created_at' => now(),
        ]);
    }

    /**
     * The subject's own team when it has one, otherwise the actor's current
     * team - a personal document still belongs to somebody's workspace.
     */
    private function teamId(Model $subject, ?User $actor): ?int
    {
        $teamId = $subject->getAttribute('team_id');

        if ($teamId !== null) {
            return (int) $teamId;
        }

        return $actor?->currentTeam?->id;
    }

    /**
     * ip()/userAgent() only mean anything inside an HTTP request. A queued
     * job or an artisan command has a Request object bound too, but its
     * values are the CLI's, not a person's - so they are left null. The test
     * runner is also "in console" while genuinely dispatching HTTP requests,
     * so it is excepted, otherwise nothing here would ever be covered.
     *
     * `request()` always resolves - a Request is bound in every context,
     * including the console - so there is no null branch to guard here; the
     * console check above is the whole of the decision.
     *
     * @param  callable(Request):(string|null)  $read
     */
    private function requestValue(callable $read): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return $read(request());
    }

    private function clip(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
