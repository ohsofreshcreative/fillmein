#!/usr/bin/env bash
#
# Figma na liście podstron: rozbijanie dużych frame'ów, dodawanie podstron bez czekania na Figmę, kolejka w tle
# i masowa „Analiza i import pól”. Bez wywołań API Figmy (linki testowe wskazują nieistniejący plik).
#
# Użycie: tests/figma-queue/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-figma-queue-ids.json
}

echo "### 1/2 Rozbijanie dużych frame'ów (bez bazy i API)"
fmiwp eval-file "$THEME/tests/figma-queue/analyzer.php" || STATUS=1

echo
echo "### 2/2 Kolejka i HTTP"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/figma-queue/lock.php" || STATUS=1
bash "$THEME/tests/figma-queue/http.sh" || STATUS=1

echo
[ "$STATUS" -eq 0 ] && echo "FIGMA — KOLEJKA: wszystkie zestawy PASS" || echo "FIGMA — KOLEJKA: wystąpiły niepowodzenia"
exit "$STATUS"
