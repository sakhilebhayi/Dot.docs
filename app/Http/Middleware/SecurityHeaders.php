<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone, so a stricter route-level
 * value (for example a PDF response that sets its own X-Frame-Options) always wins.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS only) Strict-Transport-Security.
 * HSTS is max-age only, with no includeSubDomains and no preload: this domain has many sibling subdomains that are not all ready for it.
 * SECURITY_HSTS_SECONDS in .env changes the age, and 0 turns the header off (see config/security.php).
 *
 * The Content-Security-Policy is REPORT-ONLY (Content-Security-Policy-Report-Only): the browser reports what the policy would have blocked to
 * /csp-report (App\Http\Controllers\CspReportController) and blocks nothing. It is written to be compatible with Livewire and Jetstream, which
 * need inline scripts and 'unsafe-eval' (Alpine evaluates its expressions with the Function constructor). The listed outside origins are the ones
 * the Blade layouts actually load; add one only after a layout starts using it. Enforcing the policy is a later step, taken from the reports.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ];

        $hstsSeconds = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hstsSeconds > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hstsSeconds;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Outside origins in use: Google Fonts (stylesheet host and font host), Unsplash (landing and sign-in photographs) and ui-avatars.com,
     * which Jetstream's HasProfilePhoto uses for a user's default avatar.
     */
    private function policy(): string
    {
        return "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            ."font-src 'self' data: https://fonts.gstatic.com; "
            ."img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com; "
            ."media-src 'self' blob:; "
            // Local development talks to Reverb over plaintext ws:; production websockets are wss: only.
            ."connect-src 'self' wss: https:".(app()->isLocal() ? ' ws:' : '').'; '
            ."frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; "
            .'report-uri /csp-report';
    }
}
