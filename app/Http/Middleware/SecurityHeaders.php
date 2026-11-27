<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone, so a stricter route-level
 * value (for example a PDF response that sets its own X-Frame-Options) always wins.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS only) Strict-Transport-Security.
 * HSTS is max-age only, with no includeSubDomains and no preload: this domain has many sibling subdomains that are not all ready for it.
 * SECURITY_HSTS_SECONDS in .env changes the age, and 0 turns the header off (see config/security.php).
 *
 * The Content-Security-Policy is ENFORCED (R2-1 / S-06). Scripts run only from this site or with the per-request nonce,
 * which Laravel's Vite tags, Livewire's own tags and the few inline scripts in the views carry (Vite::cspNonce()); an
 * injected <script> or inline event handler is refused by the browser. 'unsafe-eval' stays: Alpine and Livewire
 * evaluate their expressions with the Function constructor, the same limit InfoDot runs with. Styles keep
 * 'unsafe-inline' (inline style attributes are still common in the views). Violations are still reported to
 * /csp-report (CspReportController). SECURITY_CSP_ENFORCE=false in .env puts it back to report-only without a deploy.
 * The listed outside origins are the ones the Blade layouts actually load; add one only after a layout starts using it.
 */
class SecurityHeaders
{
    // microphone=(self): the editor's voice typing (resources/js/app.js, Web Speech API) needs the
    // site's own pages to keep the microphone; every other origin, including frames, is refused.
    public function handle(Request $request, Closure $next): Response
    {
        // Before the response is built: every Vite and Livewire tag rendered for this request carries it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $cspHeader = config('security.csp_enforce', true) ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(self), geolocation=(), payment=(), usb=()',
            $cspHeader => $this->policy($nonce),
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
    private function policy(string $nonce): string
    {
        return "default-src 'self'; "
            ."script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'; "
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
