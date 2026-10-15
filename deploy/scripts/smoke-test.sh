#!/usr/bin/env bash
# Quick outside check of the live site.   Usage:  bash deploy/scripts/smoke-test.sh your-domain.com
# Run it from your own computer (Git Bash / WSL) or from the server. Prints OK / FAIL per line.
set -uo pipefail

DOMAIN="${1:-}"

if [ -z "$DOMAIN" ]; then
    echo "Usage: bash deploy/scripts/smoke-test.sh your-domain.com"
    exit 1
fi

FAILS=0

check() {
    local label="$1" expected="$2" actual="$3"
    if [ "$actual" = "$expected" ]; then
        echo "OK    $label"
    else
        echo "FAIL  $label (wanted $expected, got $actual)"
        FAILS=$((FAILS + 1))
    fi
}

code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$@" || echo "000"; }

check "http redirects to https" "301" "$(code "http://$DOMAIN/")"
check "home page opens"          "200" "$(code "https://$DOMAIN/")"
check "login page opens"         "200" "$(code "https://$DOMAIN/login")"
check "/.env is not served"      "403" "$(code "https://$DOMAIN/.env")"
check "/.git is not served"      "403" "$(code "https://$DOMAIN/.git/config")"
check "admin needs a login"      "302" "$(code "https://$DOMAIN/admin")"
check "random .php is a 404"     "404" "$(code "https://$DOMAIN/phpinfo.php")"

HEADERS="$(curl -sSI --max-time 20 "https://$DOMAIN/" || true)"

for header in strict-transport-security content-security-policy; do
    if echo "$HEADERS" | grep -qi "^$header:"; then
        echo "OK    header $header"
    else
        echo "FAIL  header $header is missing"
        FAILS=$((FAILS + 1))
    fi
done

if echo "$HEADERS" | grep -qi '^x-powered-by:'; then
    echo "FAIL  x-powered-by is visible (PHP version leak)"
    FAILS=$((FAILS + 1))
else
    echo "OK    x-powered-by hidden"
fi

echo
if [ "$FAILS" -eq 0 ]; then
    echo "All checks passed."
else
    echo "$FAILS check(s) failed."
    exit 1
fi
