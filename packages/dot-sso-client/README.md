# dot/sso-client 0.1.0 (R-54 phase 2)

The relying-party package that lets a Laravel platform sign people in through InfoDot (OIDC authorisation code with PKCE).
**Off by default**: `DOTSSO_ENABLED=false` means every `/sso/*` route answers 404 and both middleware pass straight
through, so merging it into a platform changes nothing until the host `.env` turns it on. Flipping the flag back is the
instant rollback.

## Where it lives and how a platform gets it (the dot-ui pattern)

InfoDot's `packages/dot-sso-client` is the **only** place this code is edited. `sync.sh <repo>` copies it into a platform at
`packages/dot-sso-client`, writes `VERSION` (line 1 InfoDot commit, line 2 package version) and `MANIFEST.sha256`, installs
the drift test `tests/Feature/DotSsoClientDriftTest.php` (editing the copy, or leaving a stray file in it, fails the test
run) and makes three additive host edits: the composer psr-4 mappings, the provider in `bootstrap/providers.php`, and a
`DotSsoClient` suite in `phpunit.xml`. Run `composer dump-autoload` after the first sync. No new repository, no registry.

## What the platform provides

1. A class implementing `Dot\SsoClient\Contracts\UserProvisioner` (read and write its own users table; never replace a
   password), named in `config('dotsso.provisioner')`.
2. A nullable, unique column for the InfoDot user id (`users.infodot_user_uuid`) in its own migration.
3. Optionally its own look for `resources/views/vendor/dotsso/claim.blade.php` and `error.blade.php`.
4. Its policy in config: `auto_provision`, `landing`, `deep_link.allow`, `allow_paths`.

## Routes (all 404 while disabled)

| Route | What |
|---|---|
| `GET /sso/login` | Starts authorisation code + PKCE (S256) + state + nonce. All three secrets live in the server-side session, never in a URL. The deep link is the platform's own `url.intended`, validated as a same-origin path against the allowlist, kept in the same server-side record. `?screen=register` (the only accepted value) adds `prompt=create`. |
| `GET /sso/callback` | Spends the state, exchanges the code server to server, verifies the id_token (RS256 only, issuer, audience, azp, nonce, `iat`/`exp` and lifetimes, JWKS with one key-rotation refresh), resolves the account, signs in, regenerates the session, redirects to the deep link or the landing path. |
| `POST /sso/logout` | Signs out here, then RP-initiated logout at InfoDot with `id_token_hint`, which signs the person out of every platform by back-channel. |
| `POST /sso/backchannel-logout` | Server to server: verifies the logout token (signature, issuer, audience, events, no nonce, freshness, one-time `jti`), records the revocation, deletes the person's database sessions. No session, no CSRF cookie. |
| `GET/POST /sso/claim`, `/resend`, `/cancel` | The claim flow below. |

## Matching a person (never any other order)

1. the local account already holding their InfoDot user id (`sub`);
2. a local account with their email, linked **only** when InfoDot says `email_verified` **and** the local account is
   email-verified, and it is not already linked to someone else;
3. otherwise the **claim flow**: nothing is replaced or detached; a six-digit code goes to the address on file through the
   platform's own mailer (HMAC at rest, 10 minutes, 5 tries, 3 sends per address and 10 per IP per 15 minutes, 30 guesses per
   IP); only the right code links the account and confirms the email;
4. a new account, only if `auto_provision` is true, with the InfoDot id stored.

## Local-auth interception

While enabled, GET `/login`, `/register` (to `?screen=register`), `/forgot-password`, `/reset-password*` go to `/sso/login`,
and credential POSTs to them are refused (303). Never touched: JSON and bearer requests, `api/*`, `sanctum/*`,
`webhooks/*`, broadcasting, health checks, signed URLs, the platform's `allow_paths`. `DOTSSO_ALLOW_LOCAL_LOGIN=true` is the
administrator's break-glass: the local pages work again while it is set.

## Failure handling and logging

Every failure ends on a plain page (reason code printed as a reference, one next step, no automatic redirect, so no loop).
Logging is `dotsso.<event>` with reason codes only; tokens, codes, secrets, passwords and whole email addresses are never
logged (identities appear as a short keyed tag).

## Tests

`tests/` is part of the package and runs in every host (`DotSsoClient` suite). They use a fake provider that signs real
RS256 tokens and checks PKCE like InfoDot does.

## Not in this version

Company/team data in the token (the legacy `/go` redeem carries teams; OIDC claims do not yet), `sid`-level logout,
refresh-token use (none is stored), a configurable claim-code length below 4.
