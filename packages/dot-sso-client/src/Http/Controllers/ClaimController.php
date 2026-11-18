<?php

namespace Dot\SsoClient\Http\Controllers;

use Dot\SsoClient\Support\ClaimService;
use Dot\SsoClient\Support\Sso;
use Dot\SsoClient\Support\SsoError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/** The pages around the claim flow. The view is the platform's to style: resources/views/vendor/dotsso/claim.blade.php. */
class ClaimController extends Controller
{
    public function __construct(private readonly ClaimService $claims, private readonly SsoController $sso) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $claim = $this->claims->current($request);

        if (! $claim) {
            return SsoError::page($request, 'claim_missing');
        }

        return response()->view('dotsso::claim', [
            'maskedEmail' => Sso::maskEmail($claim['email']),
            'codeLength' => (int) config('dotsso.claim.code_length', 6),
            'minutes' => (int) ceil(config('dotsso.claim.ttl_seconds', 600) / 60),
            'maxAttempts' => (int) config('dotsso.claim.max_attempts', 5),
            'verifyUrl' => route('dotsso.claim.verify'),
            'resendUrl' => route('dotsso.claim.resend'),
            'cancelUrl' => route('dotsso.claim.cancel'),
        ])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request): Response|RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^\s*\d{'.(int) config('dotsso.claim.code_length', 6).'}\s*$/']], [
            'code.required' => 'Enter the code from the email.',
            'code.regex' => 'The code is '.(int) config('dotsso.claim.code_length', 6).' digits.',
        ]);

        $result = $this->claims->check($request, trim((string) $request->input('code')));

        if ($result === null) {
            return $this->claims->complete($request, $this->sso);
        }

        if (str_starts_with($result, 'wrong:')) {
            $left = (int) substr($result, 6);

            return back()->withErrors(['code' => 'That code is not right. You have '.$left.' '.($left === 1 ? 'try' : 'tries').' left.'])->withInput([]);
        }

        if (in_array($result, ['claim_locked', 'claim_expired', 'claim_missing'], true)) {
            $this->claims->forget($request);
        }

        return SsoError::page($request, $result);
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! $this->claims->current($request)) {
            return redirect()->route('dotsso.claim');
        }

        return $this->claims->resend($request)
            ? back()->with('dotsso_status', 'A new code is on its way. Only the newest code works.')
            : back()->withErrors(['code' => 'A code was sent a moment ago. Wait a few minutes before asking for another.']);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->claims->forget($request);
        Sso::log('claim_cancelled');

        return redirect()->to(url('/'));
    }
}
