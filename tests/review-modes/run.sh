#!/usr/bin/env bash
#
# Design Review, etap 06 — tryby Przeglądaj / Komentuj (headless Chromium, publiczny prototyp z dokumentacji Figmy).
# Dane testowe z tests/review-embed/fixtures.php; zawsze sprzątane. Wymaga sieci do figma.com.
#
# Użycie: bash tests/review-modes/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2
fmi_load_secrets

[ -d /tmp/fmi-ui-audit/node_modules/playwright ] || { echo "BLOCKED: brak Playwrighta w /tmp/fmi-ui-audit"; exit 2; }

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f /tmp/fmi-review-embed.json; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-embed/fixtures.php" || exit 2

node "$THEME/tests/review-modes/modes.mjs"
CODE=$?
[ "$CODE" -eq 0 ] && echo "TRYBY PRZEGLĄDAJ / KOMENTUJ: PASS" || echo "TRYBY PRZEGLĄDAJ / KOMENTUJ: FAIL"
exit "$CODE"
