<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Production serves this app from INSIDE the web root (see
 * .ai/rules/deploy.md), so the repository's root .htaccess is the only
 * thing keeping the database file, the logs and the source from being
 * public URLs. It cannot be exercised without a web server; what can be
 * guarded is that nobody deletes it or softens it.
 */
class AppDirectoryIsNotServedTest extends TestCase
{
    public function test_the_application_directory_refuses_every_web_request(): void
    {
        $path = base_path('.htaccess');

        $this->assertFileExists($path, 'The root .htaccess must exist: without it the database is a public download in production.');

        $rules = collect(file($path, FILE_IGNORE_NEW_LINES))
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || str_starts_with($line, '#'))
            ->values()
            ->all();

        $this->assertSame([
            'Require all denied',
            'RewriteEngine On',
            'RewriteRule ^ - [F,L]',
        ], $rules);
    }

    /** The file only protects production if git actually ships it. */
    public function test_the_root_htaccess_is_tracked_by_git(): void
    {
        $ignored = collect(file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES))
            ->map(fn (string $line) => trim($line));

        $this->assertFalse($ignored->contains('.htaccess'));
        $this->assertFalse($ignored->contains('/.htaccess'));
    }
}
