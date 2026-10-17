<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shared platform registry feeds the "discover" list on the branded error
 * pages. It once pointed every platform at *.infodot.app (no DNS) and marked
 * placeholders active, so error pages linked to dead hosts.
 */
class EcosystemRegistryTest extends TestCase
{
    private function platforms(): array
    {
        return (require __DIR__.'/../../config/ecosystem.php')['platforms'];
    }

    public function test_no_default_points_at_the_dead_infodot_app_domain(): void
    {
        foreach ($this->platforms() as $key => $platform) {
            $this->assertStringNotContainsString('infodot.app', $platform['url'], $key);
            $this->assertMatchesRegularExpression('#^https://([a-z]+\.)?infodot\.co\.za$#', $platform['url'], $key);
        }
    }

    public function test_urls_are_unique(): void
    {
        $urls = array_column($this->platforms(), 'url');

        $this->assertSame(count($urls), count(array_unique($urls)));
    }

    public function test_only_platforms_with_a_deployed_application_are_active(): void
    {
        $active = array_keys(array_filter($this->platforms(), fn (array $platform) => $platform['active']));
        sort($active);

        $this->assertSame(
            ['agents', 'central', 'charts', 'docs', 'dopemine', 'engage', 'files', 'forms', 'infodot', 'memory', 'mines', 'press', 'projects', 'sheet'],
            $active,
        );
    }

    public function test_hosts_that_differ_from_the_registry_key_are_mapped(): void
    {
        $platforms = $this->platforms();

        $this->assertSame('https://doc.infodot.co.za', $platforms['docs']['url']);
        $this->assertSame('https://pres.infodot.co.za', $platforms['press']['url']);
        $this->assertSame('https://sheets.infodot.co.za', $platforms['sheet']['url']);
        $this->assertSame('https://infodot.co.za', $platforms['infodot']['url']);
    }
}
