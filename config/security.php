<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns the header off.
    | includeSubDomains and preload are deliberately not sent: the Dot platforms share a parent domain with
    | roughly 27 sibling subdomains that are not all ready for HTTPS-only. Raise the age once the site has been
    | stable on HTTPS.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),   // 180 days

    /*
    | R2-1 / S-06: send the Content-Security-Policy as an enforced header. false sends the same policy as
    | Content-Security-Policy-Report-Only instead (reports, blocks nothing) - the switch to use if a page breaks.
    */
    'csp_enforce' => (bool) env('SECURITY_CSP_ENFORCE', true),
];
