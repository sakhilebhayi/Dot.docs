<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Confirm it is you</title>
<style>
  body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f5f5f2;color:#171815;display:grid;min-height:100vh;place-items:center;padding:16px;box-sizing:border-box}
  main{max-width:30rem;width:100%;background:#fff;border:1px solid #deded8;border-radius:6px;padding:24px}
  h1{font-size:1.25rem;margin:0 0 .5rem}
  label{display:block;font-weight:600;margin:1rem 0 .25rem}
  input{font:inherit;min-height:44px;width:100%;box-sizing:border-box;padding:0 12px;border:1px solid #171815;border-radius:6px;letter-spacing:.2em}
  button{font:inherit;font-weight:600;min-height:44px;padding:0 16px;border-radius:6px;border:1px solid #171815;background:#171815;color:#fff;cursor:pointer;margin-top:1rem}
  button.link{background:none;color:#171815}
  .err{color:#be2a23;margin:.25rem 0 0}
  .ok{color:#1c7c54}
  :focus-visible{outline:3px solid #1c7c54;outline-offset:2px}
</style>
</head>
<body>
<main>
  <h1>Confirm it is you</h1>
  <p>An account here already uses this email address. We sent a {{ $codeLength }}-digit code to <strong>{{ $maskedEmail }}</strong>. It works for {{ $minutes }} minutes.</p>
  @if (session('dotsso_status'))<p class="ok" role="status">{{ session('dotsso_status') }}</p>@endif
  <form method="post" action="{{ $verifyUrl }}">
    @csrf
    <label for="code">Code</label>
    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ $codeLength }}" required aria-describedby="code-error">
    <p id="code-error" class="err" role="alert">{{ $errors->first('code') }}</p>
    <button type="submit">Confirm</button>
  </form>
  <form method="post" action="{{ $resendUrl }}">@csrf<button class="link" type="submit">Send a new code</button></form>
  <form method="post" action="{{ $cancelUrl }}">@csrf<button class="link" type="submit">Cancel</button></form>
</main>
</body>
</html>
