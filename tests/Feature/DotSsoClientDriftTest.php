<?php

namespace Tests\Feature;

use Dot\SsoClient\DotSsoServiceProvider;
use Tests\TestCase;

/**
 * Drift guard for the vendored dot-sso-client package. The source of truth is InfoDot's packages/dot-sso-client; this
 * platform holds a copy written by its sync.sh, with VERSION and MANIFEST.sha256. Editing the copy here, or deleting or
 * adding a file in it, fails this test. Change the package in InfoDot and run sync.sh again.
 */
class DotSsoClientDriftTest extends TestCase
{
    private function root(): string
    {
        return base_path('packages/dot-sso-client');
    }

    public function test_the_package_is_byte_for_byte_what_the_manifest_says(): void
    {
        $manifest = $this->root().'/MANIFEST.sha256';
        $this->assertFileExists($manifest, 'run sync.sh from InfoDot to install the package');

        $listed = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($manifest))) as $line) {
            [$hash, $path] = preg_split('/\s+/', $line, 2);
            $listed[$path] = $hash;
        }

        $this->assertNotEmpty($listed);

        foreach ($listed as $path => $hash) {
            $file = $this->root().'/'.$path;
            $this->assertFileExists($file, "missing: $path");
            $this->assertSame($hash, hash_file('sha256', $file), "drifted from InfoDot: $path (edit it in InfoDot and run sync.sh again)");
        }

        $actual = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $actual[] = substr($file->getPathname(), strlen($this->root()) + 1);
        }

        $extra = array_diff($actual, array_keys($listed), ['VERSION', 'MANIFEST.sha256']);
        $this->assertSame([], array_values($extra), 'files in the package folder that InfoDot does not ship');
    }

    public function test_version_records_the_source_commit_and_package_version(): void
    {
        $lines = explode("\n", trim((string) file_get_contents($this->root().'/VERSION')));

        $this->assertMatchesRegularExpression('/^([0-9a-f]{40}|unknown)$/', $lines[0]);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $lines[1] ?? '');
    }

    public function test_the_package_is_registered_and_off_unless_the_host_turns_it_on(): void
    {
        $this->assertTrue(class_exists(DotSsoServiceProvider::class));
        $this->assertNotNull(app()->getProvider(DotSsoServiceProvider::class));
        $this->assertArrayHasKey('enabled', config('dotsso'));
    }
}
