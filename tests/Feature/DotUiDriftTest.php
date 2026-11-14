<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Drift guard for the shared dot-ui files. `dot-ui/sync.sh` (run from the dot-ui folder) copies the shared Blade
 * components, CSS, fonts, scripts, logos and PHP classes into this repository and writes dot-ui/MANIFEST.sha256
 * (`sha256sum` format: sha256 and path of every copied file). This test recomputes the hashes from the files in THIS repository and fails when any
 * differs, is missing, or when an unlisted file sits in a shared folder.
 *
 * Fix a failure by re-running sync.sh, never by editing the shared files here. A change the shared files need goes
 * into dot-ui first. Host styling belongs in the host's own mapping block, not in these files.
 */
class DotUiDriftTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array{version: string, files: array<string, string>} */
    private function manifest(): array
    {
        $path = $this->root().'/dot-ui/MANIFEST.sha256';
        $this->assertFileExists($path, 'dot-ui/MANIFEST.sha256 is missing: run dot-ui/sync.sh for this repository.');
        $version = '';
        $files = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) as $line) {
            if (preg_match('/^# version (\S+)$/', $line, $m)) {
                $version = $m[1];
            } elseif ($line !== '' && $line[0] !== '#' && preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m)) {
                $files[$m[2]] = $m[1];
            }
        }
        $this->assertNotEmpty($files, 'dot-ui/MANIFEST.sha256 lists no files.');

        return ['version' => $version, 'files' => $files];
    }

    public function test_every_shared_file_matches_the_manifest(): void
    {
        $manifest = $this->manifest();
        $drift = [];

        foreach ($manifest['files'] as $relative => $expected) {
            $file = $this->root().'/'.$relative;
            if (! is_file($file)) {
                $drift[] = "missing: {$relative}";

                continue;
            }
            if (hash_file('sha256', $file) !== $expected) {
                $drift[] = "changed: {$relative}";
            }
        }

        $this->assertSame([], $drift, "Shared dot-ui files drifted from dot-ui {$manifest['version']}. Re-run sync.sh.\n".implode("\n", $drift));
    }

    public function test_no_unlisted_file_sits_in_a_shared_folder(): void
    {
        $manifest = $this->manifest();
        $listed = array_keys($manifest['files']);
        $extra = [];

        foreach (['resources/views/components/dot', 'resources/js/dot-ui', 'public/dot-ui', 'dot-ui/src'] as $folder) {
            $base = $this->root().'/'.$folder;
            if (! is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()))), '/');
                if (str_contains($relative, '/.compiled/')) {
                    continue; // the Vue tests compile the components here; it is gitignored
                }
                if (! in_array($relative, $listed, true)) {
                    $extra[] = $relative;
                }
            }
        }

        $this->assertSame([], $extra, "Files in shared dot-ui folders that the manifest does not list:\n".implode("\n", $extra));
    }

    public function test_version_file_matches_the_manifest(): void
    {
        $manifest = $this->manifest();
        $version = trim((string) @file_get_contents($this->root().'/dot-ui/VERSION'));

        $this->assertSame($manifest['version'], $version, 'dot-ui/VERSION and MANIFEST.sha256 disagree.');
    }
}
