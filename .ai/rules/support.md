---
paths:
  - 'app/Services/WebhookService.php'
  - 'app/Support/SsrfGuard.php'
---

# Support

## Webhook delivery never follows redirects
OUTBOUND WEBHOOKS. App\Support\SsrfGuard::isSafeUrl() vets a URL, not a conversation - it resolves the host and rejects loopback, RFC1918, link-local (169.254.169.254), CGNAT (100.64.0.0/10, checked by hand because filter_var counts it neither private nor reserved) and any non-http(s) scheme. It cannot see where that target then redirects to, so WebhookService::fire() sends ->withoutRedirecting(): Guzzle would otherwise follow up to five hops unchecked, and a document owner could self-approve a public webhook that answers 302 http://169.254.169.254/ and walk this app network context to the metadata endpoint. A webhook receiver has no legitimate reason to redirect - a 3xx is a failed delivery. Anything else in this app that posts to a user-supplied URL follows the same two rules: guard the URL immediately before the request (DNS can change after save - rebinding) and do not follow redirects. A blocked delivery SKIPS, never throws into the request that triggered it, and logs the row id only - never the URL or the payload. Covered by tests/Feature/WebhookSsrfTest.php.
