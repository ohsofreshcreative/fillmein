#!/usr/bin/env bash
#
# Etap 10 — aktualizacje z Figmy, przegląd treści i dziennik na lokalnym LocalWP.
#
# 1. Logika: diff v1 → v2 w transakcji wycofywanej.
# 2. Uprawnienia, HTTP i skutki na danych testowych etapu 03 (sprzątane na końcu).
# 3. LIVE: dwie synchronizacje pod rząd; bez FMI_FIGMA_TOKEN i FMI_FIGMA_LIVE_URL NOT_RUN (kod 3).
#
# Użycie: tests/stage10/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage10-ids.json
}

echo "### 1/3 Logika"
fmiwp eval-file "$THEME/tests/stage10/logic.php" || STATUS=1

echo
echo "### 2/3 Uprawnienia, HTTP, skutki"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage10/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }
fmiwp eval-file "$THEME/tests/stage10/permissions.php" || STATUS=1
bash "$THEME/tests/stage10/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage10/effects.php" || STATUS=1

echo
echo "### 3/3 LIVE (Figma API)"
fmiwp eval-file "$THEME/tests/stage10/live.php"
LIVE=$?

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 10: wystąpiły niepowodzenia"; exit 1
fi

if [ "$LIVE" -eq 3 ]; then
  echo "ETAP 10: logika, uprawnienia i HTTP PASS; LIVE NOT_RUN"; exit 3
fi

[ "$LIVE" -eq 0 ] && echo "ETAP 10: wszystkie zestawy PASS (w tym LIVE)" || echo "ETAP 10: test LIVE FAIL"
exit "$LIVE"
