#!/usr/bin/env bash
# Read-only post-deploy smoke check for a FIBES host.
#
#   scripts/smoke-check.sh                 # checks https://www.fibes.io
#   scripts/smoke-check.sh staging.example # any other host
#
# It only sends GETs and deliberately bad, unsigned POSTs that the app must
# reject before touching the database. It needs no credentials and stores
# nothing. Exit code 0 = every check passed, 1 = at least one failed.
# See docs/post-deploy-checklist.md for the manual sign-in and 2FA steps.
set -uo pipefail

host="${1:-www.fibes.io}"
host="${host#https://}"
host="${host#http://}"
host="${host%%/*}"
base="${SMOKE_BASE:-https://${host}}"
http_base="${SMOKE_HTTP_BASE:-http://${host}}"
timeout="${SMOKE_TIMEOUT:-15}"
curl_opts=(--silent --show-error --max-time "$timeout" ${SMOKE_CURL_OPTS:-})

pass=0
fail=0
warn=0

ok() { pass=$((pass + 1)); printf 'PASS  %s\n' "$1"; }
bad() { fail=$((fail + 1)); printf 'FAIL  %s\n' "$1"; }
note() { warn=$((warn + 1)); printf 'WARN  %s\n' "$1"; }

# status <method> <url> [extra curl args...] -> prints the HTTP status code
status() {
    local method="$1" url="$2"
    shift 2
    curl "${curl_opts[@]}" -o /dev/null -w '%{http_code}' -X "$method" "$@" "$url" 2>/dev/null || printf '000'
}

# headers <url> -> prints response headers (no redirects followed)
headers() {
    curl "${curl_opts[@]}" -o /dev/null -D - "$1" 2>/dev/null | tr -d '\r'
}

# expect_reject <label> <method> <path> [curl args...]: must be a 4xx, never 2xx/5xx
expect_reject() {
    local label="$1" method="$2" path="$3"
    shift 3
    local code
    code=$(status "$method" "${base}${path}" "$@")
    case "$code" in
        4??) ok "$label rejects bad input ($code)" ;;
        *) bad "$label should reject bad input with a 4xx, got $code" ;;
    esac
}

# expect_reject_or_off: same, but a 503 means "this integration is switched off" by design.
expect_reject_or_off() {
    local label="$1" method="$2" path="$3"
    shift 3
    local code
    code=$(status "$method" "${base}${path}" "$@")
    case "$code" in
        4??) ok "$label rejects bad input ($code)" ;;
        503) note "$label answered 503: the integration is switched off (no secret set), which is expected until it is connected" ;;
        *) bad "$label should reject bad input with a 4xx, got $code" ;;
    esac
}

echo "Smoke check for ${base} ($(date '+%Y-%m-%d %H:%M %Z'))"

# 1. Plain http goes to https.
loc=$(headers "${http_base}/login" | awk 'tolower($1) == "location:" {print $2; exit}')
code=$(status GET "${http_base}/login")
if [[ "$code" =~ ^30[1278]$ && "$loc" == https://* ]]; then
    ok "http redirects to https ($code -> $loc)"
else
    bad "http should redirect to https, got $code ${loc:-no Location}"
fi

# 2. HSTS and /login.
login_headers=$(headers "${base}/login")
if grep -qi '^strict-transport-security:.*max-age=' <<<"$login_headers"; then
    ok "HSTS header present on /login"
else
    bad "HSTS header missing on /login (proxy not trusted? see TRUSTED_PROXIES)"
fi

login_code=$(status GET "${base}/login")
login_body=$(curl "${curl_opts[@]}" "${base}/login" 2>/dev/null || true)
if [[ "$login_code" == 200 ]] && grep -q 'data-page' <<<"$login_body"; then
    ok "/login loads (200, Inertia page)"
else
    bad "/login should load with 200, got $login_code"
fi

if grep -Eqi "https?://${host//./\\.}:[0-9]+" <<<"$login_body"; then
    bad "/login HTML links to ${host} with an explicit port"
else
    ok "/login HTML has no host:port links"
fi

# 3. Health.
code=$(status GET "${base}/up")
[[ "$code" == 200 ]] && ok "/up is 200" || bad "/up should be 200, got $code"

health=$(curl "${curl_opts[@]}" "${base}/health" 2>/dev/null || true)
code=$(status GET "${base}/health")
if [[ "$code" == 200 ]] && grep -q '"status":"ok"' <<<"$health"; then
    ok "/health is 200 with database ok"
else
    bad "/health should be 200 and ok, got $code ${health:0:80}"
fi

# 4. Redirects never leak the container port.
for path in /build /storage/; do
    loc=$(headers "${base}${path}" | awk 'tolower($1) == "location:" {print $2; exit}')
    if [[ -n "$loc" && "$loc" =~ ^http://|:[0-9]+/ ]]; then
        bad "${path} redirects to ${loc} (should stay on https without a port)"
    else
        ok "${path} does not redirect to http or a port${loc:+ ($loc)}"
    fi
done

# 5. Webhooks and token endpoints refuse unsigned or bad requests (4xx, never 5xx).
json=(-H 'Content-Type: application/json' -H 'Accept: application/json')
expect_reject "web-form webhook (bad signature)" POST /api/v1/hooks/web-form "${json[@]}" -H 'X-Fibes-Signature: smoke-check' --data '{"name":"smoke","phone":"0"}'
expect_reject "Meta verify (bad token)" GET '/api/v1/hooks/meta?hub.mode=subscribe&hub.verify_token=smoke-check&hub.challenge=1' -H 'Accept: application/json'
expect_reject "Meta webhook (bad signature)" POST /api/v1/hooks/meta "${json[@]}" -H 'X-Hub-Signature-256: sha256=00' --data '{}'
expect_reject_or_off "ERPNext webhook (unsigned)" POST /api/v1/hooks/erpnext "${json[@]}" --data '{}'
expect_reject_or_off "Whish webhook (unsigned)" POST /api/v1/hooks/whish "${json[@]}" --data '{}'
expect_reject "WhatsApp verify (bad token)" GET '/api/v1/hooks/whatsapp?hub.mode=subscribe&hub.verify_token=smoke-check&hub.challenge=1' -H 'Accept: application/json'
expect_reject_or_off "telephony webhook (unsigned)" POST /api/telephony/incoming "${json[@]}" --data '{}'
expect_reject "automations ping (no key)" GET /api/v1/automations/ping -H 'Accept: application/json'
expect_reject "TV feed (no token)" GET /api/v1/tv/sales -H 'Accept: application/json'
expect_reject "export download (made-up token)" GET /exports/smoke-check-not-a-token -H 'Accept: application/json'

expect_reject_or_off "WhatsApp webhook (bad signature)" POST /api/v1/hooks/whatsapp "${json[@]}" -H 'X-Hub-Signature-256: sha256=00' --data '{}'

echo
echo "Passed: ${pass}  Failed: ${fail}  Warnings: ${warn}"
echo "Now do the manual sign-in and 2FA steps in docs/post-deploy-checklist.md."
[[ "$fail" -eq 0 ]]
