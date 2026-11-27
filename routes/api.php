<?php

use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EcosystemMembershipController;
use App\Http\Controllers\Api\EcosystemSearchController;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\LogApiUse;
use App\Http\Middleware\RequireApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The token API (R2-1 / S-07). Tokens only - a browser session never reaches these, and a token never reaches a page
// (RefuseApiTokensOnPages). Each route needs the permission its token was given; every call is logged (LogApiUse).
Route::middleware(['auth:sanctum', RequireApiToken::class, EnsureCompanyEntitled::class, LogApiUse::class, 'throttle:api'])->group(function () {
    Route::get('/user', fn (Request $request) => $request->user()->only(['id', 'name', 'email']))
        ->middleware('abilities:read')->name('api.user');

    Route::get('/documents', [DocumentController::class, 'index'])->middleware('abilities:read')->name('api.documents.index');
    Route::get('/documents/{uuid}', [DocumentController::class, 'show'])->middleware('abilities:read')->name('api.documents.show');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('abilities:create')->name('api.documents.store');
    Route::put('/documents/{uuid}', [DocumentController::class, 'update'])->middleware('abilities:update')->name('api.documents.update');
});

// InfoDot tells this app, signed, that a company's membership changed, so access ends at once.
// Authenticated by the signature, not by a session. See EcosystemMembershipController.
Route::post('/ecosystem/membership', EcosystemMembershipController::class)
    ->middleware('throttle:60,1')
    ->name('api.ecosystem.membership');

// InfoDot's header search asks which of a company's documents match. Signed, answers only members.
Route::post('/ecosystem/search', EcosystemSearchController::class)
    ->middleware('throttle:120,1')
    ->name('api.ecosystem.search');
