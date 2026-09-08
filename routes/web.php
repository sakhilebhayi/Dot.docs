<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DocumentExportController;
use App\Http\Controllers\DocumentImageController;
use App\Http\Controllers\DocumentImportController;
use App\Livewire\Documents\DocumentSettings;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\Index;
use App\Livewire\Documents\ShareManager;
use App\Livewire\Documents\SlashCommandManager;
use App\Livewire\Documents\VersionHistory;
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

    return view('documents.shared', compact('document'));
})->name('documents.shared.unlock');

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

    // Export
    Route::get('/documents/{uuid}/export/{format}', [DocumentExportController::class, 'export'])
        ->where('format', 'pdf|word|html|markdown')
        ->name('documents.export');

    // Import
    Route::post('/documents/{uuid}/import', [DocumentImportController::class, 'store'])
        ->name('documents.import');
});
