<?php

use App\Http\Controllers\Api\EcosystemMembershipController;
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
