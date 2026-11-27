#!/usr/bin/env bash
# Is the live site healthy? Run ON THE HOST by the deploy (R2-2), from the app folder, after going live:
#
#   SITE_URL=https://doc.infodot.co.za bash scripts/deploy/smoke-check.sh
#
# Exit 0  healthy:      / and /login answer as the app, every build asset they reference answers 200, POST /up is no
#                       debug page, and the security headers are there (enforced CSP passes, report-only warns).
# Exit 1  unhealthy:    the APP answered and something is broken (a 5xx, a page without its assets, an asset that
#                       404s, a debug page, a missing header). The deploy rolls back on this answer only.
# Exit 2  inconclusive: the app could not be reached (a firewall or challenge page, 403/429, no answer) after
#                       retries. Nothing proves the release broken, so nothing is rolled back; the run is red.
#
# Why on the host: the host's bot protection answers GitHub's runners with its own ~12 KB page some of the time
# (2026-11-27, twice in a row). Here the request goes through the host's own loopback (--resolve) when the loopback
# serves THIS site's app (its page names $SITE_URL/build/ assets), otherwise through the public address.
set -uo pipefail

SITE_URL="${SITE_URL:?SITE_URL is required}"
HOST="${SITE_URL#https://}"; HOST="${HOST%%/*}"
TRIES="${SMOKE_TRIES:-3}"
PAUSE="${SMOKE_PAUSE:-10}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
BROKEN=0
UNREACHED=0
: > "$WORK/assets"

CURL=(curl -s --max-time 30)
is_app_page() { grep -qF "$SITE_URL/build/" "$1" 2>/dev/null; }

if curl -s --max-time 10 -o "$WORK/loop.html" --resolve "$HOST:443:127.0.0.1" "$SITE_URL/" && is_app_page "$WORK/loop.html"; then
  CURL+=(--resolve "$HOST:443:127.0.0.1")
  echo "== checking through the host's loopback (127.0.0.1 serves this site)"
else
  echo "== the loopback does not serve this site; checking through the public address"
fi

# fetch PAGE -> sets STATUS; retries while the answer is not the app (a challenge page, 403/429, a 5xx, no answer).
fetch() {
  local page="$1" i
  for i in $(seq 1 "$TRIES"); do
    STATUS=$("${CURL[@]}" -o "$WORK/page.html" -D "$WORK/page.headers" -w '%{http_code}' "$page" || true)
    STATUS="${STATUS:-000}"
    if [ "$STATUS" = "200" ] && is_app_page "$WORK/page.html"; then return 0; fi
    if [ "$STATUS" = "302" ] || [ "$STATUS" = "301" ]; then return 0; fi
    if [ "$i" -lt "$TRIES" ]; then echo "try $i: $page answered $STATUS ($(wc -c < "$WORK/page.html" | tr -d ' ') bytes); again in ${PAUSE}s"; sleep "$PAUSE"; fi
  done
}

for PAGE in "$SITE_URL/" "$SITE_URL/login"; do
  fetch "$PAGE"
  if [ "$PAGE" = "$SITE_URL/" ]; then cp "$WORK/page.headers" "$WORK/headers.txt"; fi

  # Central sign-in: /login hands over to this app's own /sso/login, which must itself answer.
  if [ "$PAGE" = "$SITE_URL/login" ] && [ "$STATUS" = "302" ]; then
    TARGET=$("${CURL[@]}" -o /dev/null -w '%{redirect_url}' "$PAGE" || true)
    if [ "$TARGET" = "$SITE_URL/sso/login" ]; then
      HOP=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$TARGET" || true)
      case "$HOP" in
        200|302) echo "302 $PAGE -> $TARGET ($HOP): central sign-in is on"; continue ;;
        5??) echo "::error::$PAGE hands over to $TARGET, which answered $HOP"; BROKEN=1; continue ;;
        *) echo "::warning::$PAGE hands over to $TARGET, which answered ${HOP:-nothing}"; UNREACHED=1; continue ;;
      esac
    fi
  fi

  case "$STATUS" in
    200)
      if is_app_page "$WORK/page.html"; then
        echo "200 $PAGE"
        { grep -oE "(href|src)=[\"'][^\"']*" "$WORK/page.html" || true; } \
          | sed -E "s/^(href|src)=[\"']//" \
          | awk -v prefix="$SITE_URL/build/" 'index($0, prefix) == 1' \
          | sort -u >> "$WORK/assets"
      else
        # A 200 that is not this app: a challenge page, or another site. It proves nothing about the release.
        echo "::warning::$PAGE answered 200 ($(wc -c < "$WORK/page.html" | tr -d ' ') bytes) but not with this app's page (a firewall or challenge page?)"
        UNREACHED=1
      fi ;;
    5??) echo "::error::$PAGE answered $STATUS"; BROKEN=1 ;;
    *) echo "::warning::$PAGE answered $STATUS; the app could not be reached"; UNREACHED=1 ;;
  esac
done

sort -u "$WORK/assets" > "$WORK/unique"
while IFS= read -r ASSET; do
  STATUS=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$ASSET" || true)
  case "$STATUS" in
    200) echo "200 $ASSET" ;;
    404|5??) echo "::error::$ASSET answered $STATUS (the page names an asset that is not served)"; BROKEN=1 ;;
    *) echo "::warning::$ASSET answered ${STATUS:-nothing}"; UNREACHED=1 ;;
  esac
done < "$WORK/unique"

# Headers and the debug probe only mean something once the app itself has answered.
if [ -s "$WORK/unique" ]; then
  "${CURL[@]}" -o "$WORK/probe.html" -X POST "$SITE_URL/up" || true
  if grep -qiE 'vendor/laravel|stack trace' "$WORK/probe.html" 2>/dev/null; then
    echo "::error::POST $SITE_URL/up answered with a debug error page: APP_DEBUG is on."; BROKEN=1
  else
    echo "No debug page on POST $SITE_URL/up"
  fi
  # GET /up runs the app's health hooks with the WEB PHP, right after the migrations (R2-5): among them the guard that
  # takes the full-text triggers out again if this PHP cannot load SQLite's FTS5 (App\Search\FullTextIndex::guard()).
  UP=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$SITE_URL/up" || true)
  case "$UP" in
    200) echo "200 $SITE_URL/up" ;;
    5??) echo "::error::GET $SITE_URL/up answered $UP."; BROKEN=1 ;;
    *) echo "::warning::GET $SITE_URL/up answered ${UP:-nothing}" ;;
  esac
  for H in x-content-type-options x-frame-options referrer-policy; do
    if grep -qi "^$H:" "$WORK/headers.txt" 2>/dev/null; then echo "Header present: $H"; else echo "::error::$SITE_URL/ answered without the $H header."; BROKEN=1; fi
  done
  if grep -qi "^content-security-policy:" "$WORK/headers.txt" 2>/dev/null; then
    echo "Header present: content-security-policy (enforced)"
  elif grep -qi "^content-security-policy-report-only:" "$WORK/headers.txt" 2>/dev/null; then
    echo "::warning::The Content-Security-Policy is report-only (SECURITY_CSP_ENFORCE=false in .env)."
  else
    echo "::error::$SITE_URL/ answered without a Content-Security-Policy header."; BROKEN=1
  fi
fi

if [ "$BROKEN" -ne 0 ]; then
  echo "::error::Smoke check: UNHEALTHY - the app answered and something is broken (see above)."
  exit 1
fi
if [ "$UNREACHED" -ne 0 ]; then
  echo "::error::Smoke check: INCONCLUSIVE - the app could not be reached (see above). Nothing is rolled back; check the site by hand."
  exit 2
fi
echo "== Smoke check passed: pages and $(wc -l < "$WORK/unique" | tr -d ' ') build asset(s) answered."
