<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * The plain page a person gets when their company does not hold Dot.docs (R-50): at sign-in, when InfoDot says no
 * company of theirs is entitled, and during a session, when the current company's access has ended. Worded from
 * the reason InfoDot gave (a redeem `access` reason or a read-endpoint `status`); it shows no document.
 */
class EntitlementRefusal
{
    /**
     * @param  iterable<int, object>  $otherCompanies  the person's other companies here, to switch to
     */
    public static function response(string $reason, ?string $company, iterable $otherCompanies = []): Response
    {
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');

        return response()
            ->view('entitlement.refused', [
                ...self::wording($reason, $company),
                'infodotUrl' => $infodot,
                'platformsUrl' => $infodot.'/platforms/'.config('ecosystem.infodot.platform', 'docs'),
                'otherCompanies' => collect($otherCompanies)->values(),
            ], 403)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array{stamp: string, title: string, body: string}
     */
    public static function wording(string $reason, ?string $company): array
    {
        $for = filled($company) ? $company : 'your company';

        return match ($reason) {
            'suspended' => [
                'stamp' => 'SUSPENDED',
                'title' => "Dot.docs is suspended for {$for}",
                'body' => 'Nobody in the company can open documents here until the suspension is lifted. They are kept as they are. Platforms in InfoDot shows what is needed.',
            ],
            'payment_required' => [
                'stamp' => 'PAYMENT NEEDED',
                'title' => "Dot.docs needs a payment for {$for}",
                'body' => 'Documents open again once the payment is settled. They are kept as they are. An owner or administrator can settle it from Platforms in InfoDot.',
            ],
            'subscription_expired', 'trial_ended', 'expired', 'cancelled' => [
                'stamp' => 'ENDED',
                'title' => "Dot.docs has ended for {$for}",
                'body' => 'The company no longer holds this platform. Its documents are kept as they are. An owner or administrator can take it up again from Platforms in InfoDot.',
            ],
            'activation_pending' => [
                'stamp' => 'NOT YET ON',
                'title' => "Dot.docs is waiting to be switched on for {$for}",
                'body' => 'The request is in and has not been completed. Platforms in InfoDot shows where it stands.',
            ],
            'not_permitted_for_you' => [
                'stamp' => 'NOT ON YOUR LIST',
                'title' => "{$for} has not given you Dot.docs",
                'body' => 'The company holds this platform and chooses who uses it. An owner or administrator can add you from Platforms in InfoDot.',
            ],
            'no_personal_access', 'company_required' => [
                'stamp' => 'COMPANY NEEDED',
                'title' => 'Dot.docs needs a company that has switched it on',
                'body' => 'A personal workspace does not hold this platform. Add or join a company in InfoDot and switch Dot.docs on for it from Platforms. Nothing in this workspace has been removed.',
            ],
            'temporarily_unavailable', 'not_launched' => [
                'stamp' => 'UNAVAILABLE',
                'title' => 'Dot.docs is not available right now',
                'body' => 'InfoDot is not handing anyone over to this platform at the moment. Platforms in InfoDot shows when it is back.',
            ],
            default => [
                'stamp' => 'NOT SWITCHED ON',
                'title' => "Dot.docs is not switched on for {$for}",
                'body' => 'An owner or administrator can switch it on from Platforms in InfoDot. Every document is kept as it is.',
            ],
        };
    }
}
