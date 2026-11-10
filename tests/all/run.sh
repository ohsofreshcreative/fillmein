#!/usr/bin/env bash
#
# Pełna regresja: każdy zestaw w osobnym procesie, tabela zestaw / kod wyjścia / czas.
# Kod 0 tylko wtedy, gdy wszystkie zestawy zwróciły 0. Kod 3 (LIVE NOT_RUN) to niepowodzenie, bo sekrety
# są dostępne przez tests/bin/env.sh. Logi: $TMPDIR/fmi-all/<zestaw>.log.
#
# Użycie: bash tests/all/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
LOGS="${TMPDIR:-/tmp}/fmi-all"
mkdir -p "$LOGS"

SUITES=(lint client-ux project-bulk page-preview nav modules review-panel review-embed review-comments review-discussions review-links review-decisions notifications review-acceptance review-qa step00 stage03 stage04 stage05 stage05a stage06 stage07 stage08 stage09 stage10 stage11 stage12 site-schema figma-queue figma-oauth stage14)
FAILED=0
ROWS=""

for suite in "${SUITES[@]}"; do
  echo "### $suite"
  START=$SECONDS
  bash "$THEME/tests/$suite/run.sh" > "$LOGS/$suite.log" 2>&1
  CODE=$?
  TIME=$((SECONDS - START))
  [ "$CODE" -eq 0 ] || FAILED=$((FAILED + 1))
  ROWS+="$(printf '| %-9s | %3d | %4d s |' "$suite" "$CODE" "$TIME")"$'\n'
done

echo
echo "| Zestaw    | Kod |   Czas |"
echo "|-----------|-----|--------|"
printf '%s' "$ROWS"
echo
echo "Logi: $LOGS"

if [ "$FAILED" -ne 0 ]; then
  echo "REGRESJA: niepowodzenia w $FAILED z ${#SUITES[@]} zestawów"
  exit 1
fi

echo "REGRESJA: wszystkie zestawy PASS (${#SUITES[@]})"
