<?php

/*
 * Dot ecosystem SSO, relying-party side (R-20 section 16, R-54 phase 2). One canonical implementation, kept in
 * InfoDot at packages/dot-sso-client and copied into each platform with sync.sh (VERSION + MANIFEST.sha256 +
 * a drift test), exactly like dot-ui.
 *
 * With DOTSSO_ENABLED false (the default) nothing in this package changes the platform: no route answers
 * (they 404), no middleware acts, local login keeps working. That flag is the per-platform switch and the
 * instant rollback.
 */
return [
    'enabled' => (bool) env('DOTSSO_ENABLED', false),

    // Break-glass for administrators: when true the platform's own login, register and reset pages work
    // again even while DOTSSO_ENABLED is true. Off by default; turning it on is an operator decision on
    // the host, never a request parameter.
    'allow_local_login' => (bool) env('DOTSSO_ALLOW_LOCAL_LOGIN', false),

    // The identity provider. Endpoints come from its discovery document, never from the platform's config.
    // https is required unless the host is localhost, 127.0.0.1, ::1, *.localhost or *.test.
    'issuer' => rtrim((string) env('DOTSSO_ISSUER', 'https://infodot.co.za'), '/'),

    'client_id' => env('DOTSSO_CLIENT_ID'),
    'client_secret' => env('DOTSSO_CLIENT_SECRET'),

    'scopes' => env('DOTSSO_SCOPES', 'openid profile email'),

    // Exact URIs registered on InfoDot for this platform. Null = derived from the request host's own URL.
    'redirect_uri' => env('DOTSSO_REDIRECT_URI'),
    'post_logout_redirect_uri' => env('DOTSSO_POST_LOGOUT_URI'),

    // The class implementing Dot\SsoClient\Contracts\UserProvisioner (the platform provides it).
    'provisioner' => null,

    // Create a local account on first sign-in when nothing matches. The platform sets its own default.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', true),

    // Link an existing local account by email ONLY when InfoDot says email_verified AND the local account is
    // email-verified. Anything less goes to the claim flow (a code sent to the address on file). Never silent.
    'link_verified_email' => (bool) env('DOTSSO_LINK_VERIFIED_EMAIL', true),

    // The SESSION guard the package signs people in and out of. It must be 'web' (or the platform's own session guard), never
    // left to the default: on a route behind auth:sanctum, Sanctum makes `sanctum` the default guard, and its request guard
    // has no logout() (a 500 on the first request after an InfoDot sign-out).
    'guard' => env('DOTSSO_GUARD', 'web'),

    // Where a signed-in person lands when no safe deep link is known (a path on this platform).
    'landing' => '/dashboard',

    // Deep links: only a same-origin path is ever accepted, then it must match one pattern in `allow` and none
    // in `deny`. The platform narrows `allow` to its own areas.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\?|$)#i'],
    ],

    // Paths that are never intercepted (shell-style patterns for Request::is), on top of the fixed machine paths.
    'allow_paths' => [],

    // When true (default) credential POSTs to the local login, register and reset endpoints are refused while SSO is on.
    'block_credential_posts' => true,

    'state_ttl_seconds' => 600,
    'max_pending' => 5,

    'claim' => [
        'code_length' => 6,
        'ttl_seconds' => 600,
        'max_attempts' => 5,
        // Sending limits: per address and per IP, over `window_seconds`.
        'send_per_email' => 3,
        'send_per_ip' => 10,
        'verify_per_ip' => 30,
        'window_seconds' => 900,
    ],

    'http_timeout_seconds' => 10,
    'jwks_cache_seconds' => 3600,
    'leeway_seconds' => 60,
    // An id_token older than this when it arrives (iat) is refused: it was not minted for this exchange.
    'id_token_max_age_seconds' => 600,
    // No id_token may claim a lifetime longer than this.
    'id_token_max_lifetime_seconds' => 86400,
];
