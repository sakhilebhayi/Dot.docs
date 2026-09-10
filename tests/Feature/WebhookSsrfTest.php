<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentWebhook;
use App\Models\User;
use App\Services\WebhookService;
use App\Support\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SsrfGuard::isSafeUrl() resolves a hostname through DNS, which a sandboxed
 * or offline test run cannot do. Every URL here is therefore an IP LITERAL:
 * filter_var() validates those directly, so the check stays a genuine SSRF
 * check rather than a mocked one, and the test never touches the network.
 */
class WebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $owner): Document
    {
        return Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Test document',
            'owner_id' => $owner->id,
        ]);
    }

    private function webhook(Document $document, User $owner, string $url): DocumentWebhook
    {
        return DocumentWebhook::create([
            'document_id' => $document->id,
            'user_id' => $owner->id,
            'url' => $url,
            'events' => ['on_save'],
            'status' => 'active',
        ]);
    }

    public function test_fire_skips_a_webhook_pointing_at_loopback(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $this->webhook($document, $owner, 'http://127.0.0.1:8080/hook');

        (new WebhookService)->fire($document, 'on_save');

        Http::assertNothingSent();
    }

    public function test_fire_skips_a_webhook_pointing_at_a_private_range(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $this->webhook($document, $owner, 'http://10.0.0.7/hook');
        $this->webhook($document, $owner, 'http://192.168.1.10/hook');
        $this->webhook($document, $owner, 'http://169.254.169.254/latest/meta-data');

        (new WebhookService)->fire($document, 'on_save');

        Http::assertNothingSent();
    }

    public function test_fire_skips_a_non_http_scheme(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $this->webhook($document, $owner, 'file:///etc/passwd');

        (new WebhookService)->fire($document, 'on_save');

        Http::assertNothingSent();
    }

    public function test_fire_still_delivers_to_a_public_address(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $document = $this->document($owner);
        $this->webhook($document, $owner, 'https://93.184.216.34/hook');

        (new WebhookService)->fire($document, 'on_save');

        Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/hook');
    }

    public function test_the_guard_itself_rejects_loopback_and_localhost(): void
    {
        $this->assertFalse(SsrfGuard::isSafeUrl('http://localhost/hook'));
        $this->assertFalse(SsrfGuard::isSafeUrl('http://127.0.0.1/hook'));
        $this->assertFalse(SsrfGuard::isSafeUrl('http://[::1]/hook'));
        $this->assertFalse(SsrfGuard::isSafeUrl('not a url'));
        $this->assertTrue(SsrfGuard::isSafeUrl('https://93.184.216.34/hook'));
    }
}
