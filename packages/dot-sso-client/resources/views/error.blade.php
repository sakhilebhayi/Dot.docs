<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $headline }}</title>
<style>
  body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f5f5f2;color:#171815;display:grid;min-height:100vh;place-items:center;padding:16px;box-sizing:border-box}
  main{max-width:30rem;width:100%;background:#fff;border:1px solid #deded8;border-radius:6px;padding:24px}
  h1{font-size:1.25rem;line-height:1.3;margin:0 0 .5rem}
  p{margin:0 0 1rem}
  .ref{font-size:.8125rem;color:#5d5d57}
  .actions{display:flex;flex-wrap:wrap;gap:12px}
  a.btn{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;border-radius:6px;text-decoration:none;font-weight:600;border:1px solid #171815}
  a.primary{background:#171815;color:#fff}
  a.secondary{color:#171815}
  a:focus-visible{outline:3px solid #1c7c54;outline-offset:2px}
</style>
</head>
<body>
<main role="alert">
  <h1>{{ $headline }}</h1>
  <p>{{ $body }}</p>
  <div class="actions">
    @if ($retry)<a class="btn primary" href="{{ $retryUrl }}">Start again</a>@endif
    <a class="btn secondary" href="{{ $homeUrl }}">Back to the start page</a>
  </div>
  <p class="ref" style="margin-top:1rem">Reference: {{ $code }}</p>
</main>
</body>
</html>
