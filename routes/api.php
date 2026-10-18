<?php

use App\Http\Controllers\Api\EcosystemMembershipController;
use App\Http\Controllers\Api\EcosystemSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// InfoDot tells this app, signed, that a company's membership changed, so access ends at once.
// Authenticated by the signature, not by a session. See EcosystemMembershipController.
Route::post('/ecosystem/membership', EcosystemMembershipController::class)
    ->middleware('throttle:60,1')
    ->name('api.ecosystem.membership');

// InfoDot's header search asks which of a company's documents match. Signed, answers only members.
Route::post('/ecosystem/search', EcosystemSearchController::class)
    ->middleware('throttle:120,1')
    ->name('api.ecosystem.search');
