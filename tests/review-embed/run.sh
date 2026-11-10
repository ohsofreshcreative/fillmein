#!/usr/bin/env bash
#
# Design Review, etap 05 — osadzony prototyp Figmy: adres osadzenia (PHP), strona recenzji i prototyp
# w headless Chromium (publiczny, ograniczony, dotyk, resize, przewijanie, zablokowana sieć, zdarzenia).
# Wymaga sieci do figma.com i Playwrighta w /tmp/fmi-ui-audit. Dane zawsze sprzątane.
#
# Użycie: bash tests/review-embed/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2
fmi_load_secrets

[ -d /tmp/fmi-ui-audit/node_modules/playwright ] || { echo "BLOCKED: brak Playwrighta w /tmp/fmi-ui-audit"; exit 2; }

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f /tmp/fmi-review-embed.json; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT

FAIL=0
echo "== Adres osadzenia =="
fmiwp eval-file "$THEME/tests/review-embed/unit.php" || FAIL=1
fmiwp eval-file "$THEME/tests/review-embed/fixtures.php" || exit 2
echo
node "$THEME/tests/review-embed/embed.mjs"
CODE=$?
[ "$CODE" -eq 2 ] && exit 2
[ "$CODE" -eq 0 ] || FAIL=1
[ "$FAIL" -eq 0 ] && echo "OSADZENIE PROTOTYPU: PASS" || echo "OSADZENIE PROTOTYPU: FAIL"
exit "$FAIL"
