<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Jwt\ProviderMetadata;
use Dot\SsoClient\Support\Sso;
use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Support\Facades\Http;

class LogoutAndMetadataTest extends DotSsoTestCase
{
    public function test_logout_signs_out_here_and_asks_infodot_to_sign_out_everywhere(): void
    {
        $this->finishLogin($this->startLogin());
        $this->assertAuthenticated();

        $response = $this->post('https://platform.test/sso/logout')->assertRedirect();

        $this->assertGuest();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(FakeIdp::ISSUER.'/oauth/end-session?', $location);
        $q = FakeIdp::authorizeParams($location);
        $this->assertSame('https://platform.test/', $q['post_logout_redirect_uri']);
        $this->assertGreaterThanOrEqual(16, strlen($q['state']));
        $this->assertCount(3, explode('.', $q['id_token_hint']));
    }

    public function test_logout_must_be_a_post(): void
    {
        $this->get('https://platform.test/sso/logout')->assertStatus(405);
    }

    public function test_logout_without_a_provider_hint_still_signs_out_locally(): void
    {
        $this->actingAs($this->localUser());

        $this->post('https://platform.test/sso/logout')->assertRedirect('https://platform.test/');
        $this->assertGuest();
    }

    public function test_a_plain_http_issuer_is_refused_except_on_local_hosts(): void
    {
        foreach (['http://infodot.co.za', 'ftp://x', 'https://', 'https://user:pw@infodot.co.za', '//infodot.co.za'] as $bad) {
            $this->assertFalse(Sso::issuerIsAcceptable($bad), $bad);
        }
        foreach (['https://infodot.co.za', 'http://localhost:19991', 'http://127.0.0.1:19991', 'http://infodot.test'] as $ok) {
            $this->assertTrue(Sso::issuerIsAcceptable($ok), $ok);
        }
    }

    public function test_an_endpoint_on_a_lookalike_host_is_not_accepted(): void
    {
        $this->idp->discoveryPatch = ['token_endpoint' => 'https://idp.test.evil.example/oauth/token'];

        $this->expectException(\RuntimeException::class);
        ProviderMetadata::endpoint('token_endpoint');
    }

    public function test_a_discovery_document_for_a_different_issuer_is_refused(): void
    {
        $this->idp->discoveryPatch = ['issuer' => 'https://other.example'];

        $this->expectException(\RuntimeException::class);
        ProviderMetadata::discovery();
    }

    public function test_a_rotated_signing_key_is_picked_up(): void
    {
        $this->finishLogin($this->startLogin(), ['sub' => 'rot-1', 'email' => 'r1@example.test'])->assertRedirect();
        auth()->logout();
        $this->flushSession();

        // The provider rotates: new kid, new key set. The cached set does not know it, so it is refreshed once.
        $this->idp->kid = 'idp-2';
        $this->idp->jwks = [$this->idp->jwk('idp-2')];

        $this->finishLogin($this->startLogin(), ['sub' => 'rot-2', 'email' => 'r2@example.test'])->assertRedirect('/dashboard');
    }

    public function test_an_unknown_key_id_is_refused_and_refreshes_at_most_once_a_minute(): void
    {
        $this->idp->kid = 'unknown-kid';
        $this->idp->jwks = [$this->idp->jwk('idp-1')];

        for ($i = 0; $i < 3; $i++) {
            $this->finishLogin($this->startLogin())->assertStatus(403);
        }

        $jwksCalls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/oauth/jwks'))->count();
        $this->assertSame(2, $jwksCalls, 'one initial fetch and one refresh');
    }
}
