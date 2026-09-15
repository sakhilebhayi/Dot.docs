<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DocumentAutosaveController;
use App\Http\Controllers\DocumentExportController;
use App\Http\Controllers\DocumentImageController;
use App\Http\Controllers\DocumentImportController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FileViewController;
use App\Http\Controllers\PublishedDocumentController;
use App\Livewire\Documents\DocumentSettings;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\Index;
use App\Livewire\Documents\ShareManager;
use App\Livewire\Documents\SlashCommandManager;
use App\Livewire\Documents\VersionHistory;
use App\Livewire\Files\Navigator;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('auth.ecosystem');

Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

// Public shared document view (with optional password & expiry enforcement)

Route::get('/shared/{uuid}', function (string $uuid) {
    $document = Document::where('uuid', $uuid)
        ->where('is_public', true)
        ->firstOrFail();

    // Check expiry
    if ($document->share_expires_at && $document->share_expires_at->isPast()) {
        abort(410, 'This share link has expired.');
    }

    // Check password
    if ($document->share_password) {
        return view('documents.shared-password', compact('document'));
    }

    $document->recordView();

    return view('documents.shared', compact('document'));
})->name('documents.shared');

Route::post('/shared/{uuid}', function (string $uuid, Request $request) {
    $document = Document::where('uuid', $uuid)
        ->where('is_public', true)
        ->firstOrFail();

    if ($document->share_expires_at && $document->share_expires_at->isPast()) {
        abort(410, 'This share link has expired.');
    }

    $request->validate(['password' => 'required|string']);

    if (! Hash::check($request->password, $document->share_password)) {
        return back()->withErrors(['password' => 'Incorrect password.']);
    }

    $document->recordView();

    return view('documents.shared', compact('document'));
})->middleware('throttle:published-unlock')->name('documents.shared.unlock');

// The published page. Same access rules as /shared/{uuid} above, on a name
// the writer chose - see App\Http\Controllers\PublishedDocumentController.
Route::get('/d/{slug}', [PublishedDocumentController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('documents.published');

Route::post('/d/{slug}', [PublishedDocumentController::class, 'unlock'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:published-unlock')
    ->name('documents.published.unlock');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $userId = auth()->id();
        $myDocs = Document::where('owner_id', $userId)->count();
        $sharedDocs = DocumentCollaborator::where('user_id', $userId)->count();
        $publicDocs = Document::where('owner_id', $userId)->where('is_public', true)->count();
        $aiSuggestions = AiSuggestion::where('user_id', $userId)->whereNull('accepted_at')->count();
        $recentDocs = Document::where('owner_id', $userId)->latest()->limit(8)->get();
        $recentShared = DocumentCollaborator::where('user_id', $userId)
            ->with('document.owner')->latest()->limit(5)->get();

        return view('dashboard', compact('myDocs', 'sharedDocs', 'publicDocs', 'aiSuggestions', 'recentDocs', 'recentShared'));
    })->name('dashboard');

    // Documents
    Route::get('/documents', Index::class)->name('documents.index');
    Route::get('/documents/{uuid}/edit', Editor::class)->name('documents.edit');
    Route::get('/documents/{uuid}/settings', DocumentSettings::class)->name('documents.settings');
    Route::get('/documents/{uuid}/share', ShareManager::class)->name('documents.share');
    Route::get('/documents/{uuid}/history', VersionHistory::class)->name('documents.history');

    // Slash commands (user-level, not per-document)
    Route::get('/settings/slash-commands', SlashCommandManager::class)->name('slash-commands.index');

    // Image uploads inside documents
    Route::post('/documents/{uuid}/images', [DocumentImageController::class, 'store'])
        ->name('documents.images.store');

    // Last-chance autosave. The editor flushes its pending document here with
    // navigator.sendBeacon() on pagehide, where a Livewire request cannot be
    // issued at all (CommitBus defers on a 5 ms timer the unloading page never
    // runs). See App\Http\Controllers\DocumentAutosaveController.
    Route::post('/documents/{uuid}/autosave', [DocumentAutosaveController::class, 'store'])
        ->name('documents.autosave');

    // Export
    Route::get('/documents/{uuid}/export/{format}', [DocumentExportController::class, 'export'])
        ->where('format', 'pdf|word|html|markdown')
        ->name('documents.export');

    // The same export, filed in the shared tree instead of downloaded.
    Route::post('/documents/{uuid}/export/{format}/save-to-files', [DocumentExportController::class, 'saveToFiles'])
        ->where('format', 'pdf|word|html|markdown')
        ->name('documents.export.save-to-files');

    // The shared Dot.Files tree
    Route::get('/files', Navigator::class)->name('files.index');

    Route::post('/files/{parent}/upload', [FileUploadController::class, 'store'])
        ->name('files.upload');

    // Import
    Route::post('/documents/{uuid}/import', [DocumentImportController::class, 'store'])
        ->name('documents.import');

    // Reading one file out of the private `files` disk. `signed` proves the
    // link was minted here and has not expired (10 minutes); the controller
    // then checks the viewer belongs to the FILE'S team, so a signed link
    // is not a bearer token for anyone who happens to be logged in.
    Route::get('/files/{file}/view', [FileViewController::class, 'show'])
        ->middleware('signed')
        ->name('files.view');
});
