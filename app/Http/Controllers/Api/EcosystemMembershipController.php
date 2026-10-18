<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ecosystem\ApplyMembershipChange;
use App\Http\Controllers\Controller;
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
    private const TOLERANCE = 300;

    public function __invoke(Request $request, ApplyMembershipChange $apply): JsonResponse
    {
        $secret = (string) config('ecosystem.infodot.secret');
        $timestamp = (int) $request->header('X-InfoDot-Timestamp');
        $signature = (string) $request->header('X-InfoDot-Signature');

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($secret === '' || $timestamp === 0 || abs(time() - $timestamp) > self::TOLERANCE || ! hash_equals($expected, $signature)) {
            Log::warning('Membership webhook refused.', ['reason' => $secret === '' ? 'not configured' : 'bad or stale signature']);

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
