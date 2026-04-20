#!/usr/bin/env bash
# Seam-check: guard rails for recurring audit findings.
#
# Each rule catches a pattern that (a) was fixed in a past audit pass
# and (b) has low enough false-positive rate that failing the build
# on a match is worth the friction. Add new rules as audits surface
# new anti-patterns; keep the rule list in sync with todo.md →
# "Completed in this audit pass".
#
# Exits 1 on any hit; 0 when clean.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

failed=0

rule() {
    local name=$1
    local description=$2
    local pattern=$3
    shift 3
    local paths=("$@")

    if git grep -nE "$pattern" -- "${paths[@]}" 2>/dev/null; then
        echo ""
        echo "✗ Seam check failed: $name"
        echo "  $description"
        echo ""
        failed=1
    fi
}

# --- 1. Block renderers must use scoped wp_kses, not wp_kses_post ---------
# native.json declares an allowlist; wp_kses_post is always broader.
rule "block-wp-kses-post" \
    "Block renderers must use wp_kses with a scoped allowlist matching native.json, not wp_kses_post." \
    'wp_kses_post\(' \
    'src/Blocks/'

# --- 2. Dynamic-tag interpolation in block renderers ----------------------
# Enforced via `match` emitting full tags since section-heading hardening.
rule "dynamic-block-tag" \
    "Don't interpolate a variable as an HTML tag name in a block renderer — use match() to emit full open/close pairs." \
    '<<\?php echo \$(level|tag|heading)' \
    'src/Blocks/'

# --- 3. register_rest_route with empty namespace --------------------------
# Past bug — this mounts the route under /wp-json// which Apple/Google won't fetch.
# Require line to start with a non-comment char; otherwise the docblock mentions
# of the regression in WellKnown files trip the rule.
rule "empty-rest-namespace" \
    "register_rest_route with an empty namespace mounts routes under /wp-json// (past well-known regression)." \
    "^[^\*]*register_rest_route\s*\(\s*['\"]['\"]" \
    'src/'

# --- 4. error_log in src/ outside allowed sinks ---------------------------
# All plugin logging should route through ShellAccessLog or Vite.
# Anything else bypasses redaction.
rule "direct-error-log" \
    "error_log() calls in src/ must live in src/Logging/* or src/Support/Vite.php. Other sinks bypass ShellAccessLog's sensitive-key redaction." \
    'error_log\(' \
    'src/' \
    ':!src/Logging/' \
    ':!src/Support/Vite.php'

# --- 5. exit; in REST route handlers --------------------------------------
# Only the WellKnown routes (domain-root intercepts) legitimately exit;
# REST handlers must return WP_REST_Response.
if git grep -lnE '^\s*exit;\s*$' -- 'src/Api/' 2>/dev/null | \
   grep -v 'WellKnown/'; then
    echo ""
    echo "✗ Seam check failed: rest-handler-exit"
    echo "  Only src/Api/WellKnown/* may call exit;. Other routes must return a WP_REST_Response."
    echo ""
    failed=1
fi

# --- 6. Direct $_SERVER['REMOTE_ADDR'] outside throttle/auth classes -----
# Rate-limit bucketing should route through WellKnownThrottle. Widespread
# REMOTE_ADDR reads make cross-endpoint policy drift easy.
rule "raw-remote-addr" \
    "\$_SERVER['REMOTE_ADDR'] reads should live in WellKnownThrottle or WellKnown routes." \
    "\\\$_SERVER\\['REMOTE_ADDR'\\]" \
    'src/' \
    ':!src/Api/WellKnown/'

if [[ $failed -eq 0 ]]; then
    echo "✓ Seam check clean."
fi

exit $failed
