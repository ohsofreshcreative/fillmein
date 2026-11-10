#!/usr/bin/env bash
#
# Design Review, etap 12 — macierz przeglądarek / urządzeń / ról (headless). Dane: scenariusz odbiorczy + prep.php.
# Użycie: bash tests/review-qa/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f /tmp/fmi-review-acceptance.json /tmp/fmi-review-qa.json; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-acceptance/fixtures.php" || exit 2
fmiwp eval-file "$THEME/tests/review-qa/prep.php" || exit 2
node "$THEME/tests/review-qa/matrix.mjs"
