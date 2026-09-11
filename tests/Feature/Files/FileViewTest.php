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

    private function uploadedFile(User $owner, string $contents = 'the bytes'): File
    {
        $files = app(FilesService::class);
        $root = $files->root($owner->currentTeam ?? $owner->personalTeam());
        $node = $files->createFile($root, 'notes.txt', $contents, 'text/plain', $owner);

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
