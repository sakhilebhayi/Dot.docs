<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The ecosystem handoff's optional `redirect`.
 *
 * Other products link a person straight to the page they were heading for.
 * A request parameter that becomes a redirect is an open redirect unless it
 * is pinned to this origin, so the accepted shape is narrow: one leading
 * slash, no second one, no backslash, no scheme.
 */
class HandoffRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function handoff(?string $redirect = null): TestResponse
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;

        $body = ['token' => $token] + ($redirect === null ? [] : ['redirect' => $redirect]);

        return $this->post(route('auth.ecosystem'), $body, $this->fromInfodot());
    }

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $parent = str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host;

        return ['Origin' => 'https://'.$parent];
    }

    public function test_a_relative_path_is_honoured(): void
    {
        $this->handoff('/documents?filter=shared')
            ->assertRedirect('/documents?filter=shared');

        $this->assertAuthenticated();
    }

    public function test_no_redirect_lands_on_the_dashboard(): void
    {
        $this->handoff()->assertRedirect(route('dashboard'));
    }

    #[DataProvider('maliciousRedirects')]
    public function test_an_off_site_redirect_is_ignored_and_the_person_still_signs_in(string $redirect): void
    {
        $this->handoff($redirect)->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    /** @return array<string, array{0:string}> */
    public static function maliciousRedirects(): array
    {
        return [
            'absolute url' => ['https://evil.test/steal'],
            'protocol relative' => ['//evil.test/steal'],
            'backslash protocol relative' => ['/\\evil.test/steal'],
            'backslash anywhere' => ['/documents\\..\\..\\evil'],
            'scheme before the first slash' => ['/javascript:alert(1)'],
            'data uri' => ['/data:text/html,<script>'],
            'no leading slash' => ['evil.test'],
            'control character' => ["/documents\n/evil"],
        ];
    }
}
