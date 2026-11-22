<?php

use App\Sso\DocsProvisioner;

/*
 * Dot.docs' own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false) and the session guard (the package defaults it to `web`), comes from the package's
 * defaults and the host .env.
 */
return [
    'provisioner' => DocsProvisioner::class,

    // Dot.docs has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may open any path of the app (same-origin only, checked by the package): every document page
    // (/documents/{uuid}/edit, /o/{uuid}, /files, team invitations) is a signed-in page and must survive the sign-in.
    // The deny-list keeps the auth pages out, so no loop is possible.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\?|$)#i'],
    ],

    // Never intercepted: nothing extra. The machine doors (the InfoDot-signed membership and search endpoints under
    // /api/ecosystem/*, Sanctum and Jetstream API tokens, signed file URLs, /up, /guardian) and the outside-party pages
    // (share links /shared/{uuid}, published pages /d/{slug}) are outside the pages the package takes over.
    'allow_paths' => [],
];
