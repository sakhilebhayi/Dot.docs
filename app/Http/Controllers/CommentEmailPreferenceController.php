<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The comment-email switch behind the link at the foot of every comment email
 * (EA-3 / E-06). The signed address is the credential: it works without
 * signing in, from any mail client, and never expires, as an unsubscribe link
 * must. A POST is the RFC 8058 one-click unsubscribe a mail client sends
 * (`List-Unsubscribe=One-Click`), or the page's own button (`state=on|off`).
 */
class CommentEmailPreferenceController extends Controller
{
    public static function link(User $user): string
    {
        return URL::signedRoute('email.comments', ['user' => $user->id]);
    }

    public function show(User $user)
    {
        return view('email.comment-preference', [
            'on' => (bool) $user->comment_emails,
            'action' => self::link($user),
            'changed' => session('comment_emails_changed', false),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $on = $request->input('state') === 'on';

        $user->forceFill(['comment_emails' => $on])->save();

        // A mail client's one-click request wants a plain success, not a page.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent();
        }

        return redirect()->to(self::link($user))->with('comment_emails_changed', true);
    }
}
