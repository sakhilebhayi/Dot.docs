<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The deploy-triggered LSCache purge: a signed, unauthenticated GET that
 * asks LiteSpeed (via a response header) to drop every cached page.
 */
class CachePurgeTest extends TestCase
{
    public function test_an_unsigned_request_is_refused(): void
    {
        $response = $this->get('/ops/purge-cache');

        $response->assertForbidden();
    }

    public function test_a_signed_request_returns_no_content_with_the_litespeed_purge_header(): void
    {
        $url = URL::temporarySignedRoute('ops.purge-cache', now()->addMinutes(5));

        $response = $this->get($url);

        $response->assertNoContent();
        $this->assertSame('*', $response->headers->get('X-LiteSpeed-Purge'));
    }

    public function test_the_command_prints_a_signed_url_that_the_route_accepts(): void
    {
        $output = Artisan::call('cache:purge-litespeed');
        $url = trim(Artisan::output());

        $this->assertSame(0, $output);

        $response = $this->get($url);

        $response->assertNoContent();
        $this->assertSame('*', $response->headers->get('X-LiteSpeed-Purge'));
    }
}
