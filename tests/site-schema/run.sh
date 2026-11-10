#!/usr/bin/env bash
#
# Schemat strony WordPress (wtyczka Fill Me In Sync): algorytm dopasowania, serwis, eksport z nazwami ACF, HTTP.
#
# Dane testowe etapu 03 + zasiew tests/site-schema/seed.php; sprzątanie w trap EXIT (kaskada usuwa schemat i dopasowania).
#
# Użycie: tests/site-schema/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

cleanup() {
  fmiwp eval-file "$THEME/tests/stage11/cleanup.php" || echo "UWAGA: sprzątanie plików zgłosiło błąd."
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-site-schema-ids.json
}

echo "### 1/2 Algorytm (bez bazy)"
fmiwp eval-file "$THEME/tests/site-schema/matcher.php" || STATUS=1

echo
echo "### 2/2 Serwis, eksport, HTTP"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/site-schema/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }
# HTTP najpierw: sprawdza też stan „bez schematu”; na koniec przywraca automat, więc serwis startuje od czystych dopasowań.
bash "$THEME/tests/site-schema/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/site-schema/service.php" || STATUS=1

echo
[ "$STATUS" -eq 0 ] && echo "SCHEMAT STRONY: wszystkie zestawy PASS" || echo "SCHEMAT STRONY: wystąpiły niepowodzenia"
exit "$STATUS"
