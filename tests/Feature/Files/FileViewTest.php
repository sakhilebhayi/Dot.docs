<?php

namespace Tests\Feature\Files;

use App\Files\FilesService;
use App\Models\Files\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Reading a file out of the private `files` disk.
 *
 * Two gates, and each case here removes exactly one of them: an unsigned or
 * expired link, and a signed link handed to someone outside the file's team.
 */
class FileViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    private function uploadedFile(
        User $owner,
        string $contents = 'the bytes',
        string $name = 'notes.txt',
        string $mime = 'text/plain',
    ): File {
        $files = app(FilesService::class);
        $root = $files->root($owner->currentTeam ?? $owner->personalTeam());
        $node = $files->createFile($root, $name, $contents, $mime, $owner);

        return $node->objectable;
    }

    private function link(File $file, int $minutes = 10): string
    {
        return URL::temporarySignedRoute('files.view', now()->addMinutes($minutes), ['file' => $file->uuid]);
    }

    public function test_a_signed_link_serves_the_file_inline_with_its_stored_mime_type(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($user);

        $response = $this->actingAs($user)->get($this->link($file));

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('the bytes', $response->streamedContent());
    }

    /**
     * An SVG is a script that renders as a picture. Uploading one is refused
     * (NavigatorTest), but `files` is a SHARED table: a row written by a
     * Dot.Files instance can name any type at all, so nothing outside a
     * short inline allow-list is ever rendered in the Dot.Doc origin.
     */
    public function test_an_svg_is_handed_over_as_a_download_never_rendered_inline(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile(
            $user,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
            'logo.svg',
            'image/svg+xml',
        );

        $response = $this->actingAs($user)->get($this->link($file));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** Same rule, and the one that matters most: HTML is never served inline either. */
    public function test_html_is_handed_over_as_a_download(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($user, '<script>alert(1)</script>', 'page.html', 'text/html');

        $response = $this->actingAs($user)->get($this->link($file));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($user);

        $this->actingAs($user)
            ->get(route('files.view', $file->uuid))
            ->assertForbidden();
    }

    public function test_the_link_expires(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($user);
        $link = $this->link($file);

        Carbon::setTestNow(now()->addMinutes(11));

        $this->actingAs($user)->get($link)->assertForbidden();

        Carbon::setTestNow();
    }

    /** A signed link is not a bearer token: it still has to be YOUR team's file. */
    public function test_a_signed_link_is_refused_to_someone_outside_the_files_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($owner);

        $this->actingAs($outsider)->get($this->link($file))->assertForbidden();
    }

    public function test_a_row_whose_path_climbs_out_of_the_disk_is_a_404_not_a_read(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($user);

        // A row on a SHARED database was not necessarily written by this app.
        $file->forceFill(['path' => '../../../../etc/passwd'])->save();

        $this->actingAs($user)->get($this->link($file))->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = $this->uploadedFile($user);

        $this->get($this->link($file))->assertRedirect(route('login'));
    }
}
