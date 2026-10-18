<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ecosystem\ApplyMembershipChange;
use App\Http\Controllers\Controller;
use App\Services\InfoDot\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * InfoDot tells this app, signed, that a company's membership changed. The signature is
 * HMAC-SHA256 over "{timestamp}.{raw body}" with this app's INFODOT_SERVICE_SECRET (the one
 * it redeems handoff tokens with), and the timestamp must be fresh, so only InfoDot can send
 * it and a captured request cannot be replayed later. Every refusal looks the same.
 */
class EcosystemMembershipController extends Controller
{
    public function __invoke(Request $request, ApplyMembershipChange $apply): JsonResponse
    {
        if (! SignedRequest::valid($request)) {
            Log::warning('Membership webhook refused.', ['reason' => 'not configured, or bad or stale signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        $change = Validator::make((array) json_decode($request->getContent(), true), [
            'event' => ['required', 'in:member.removed,member.role_changed,team.deleted'],
            'team_uuid' => ['required', 'uuid'],
            'email' => ['nullable', 'email'],
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        if ($change->fails()) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $apply($change->validated());

        return response()->json(['ok' => true], 202);
    }
}
