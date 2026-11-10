#!/usr/bin/env bash
#
# Etap 07 — linki klienta i edytor /p/{token} na lokalnym LocalWP.
#
# 1. Logika: cykl życia linku, zapisy i wiersze (transakcja wycofywana).
# 2. Uprawnienia, HTTP (404, IDOR, CSRF, zapisy) i skutki, w tym przeszukanie sesji, logów, dziennika
#    i nagłówków pod kątem jawnego tokenu. Dane testowe są sprzątane na końcu.
#
# Tokeny testowe leżą tylko w pliku tymczasowym (0600), usuwanym w trap EXIT, i nie są wypisywane.
#
# Użycie: tests/stage07/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

echo "### 1/2 Logika"
fmiwp eval-file "$THEME/tests/stage07/logic.php" || STATUS=1

echo
echo "### 2/2 Uprawnienia, HTTP, skutki"
cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage07-ids.json
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage07/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }
fmiwp eval-file "$THEME/tests/stage07/permissions.php" || STATUS=1
bash "$THEME/tests/stage07/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage07/effects.php" || STATUS=1

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 07: wystąpiły niepowodzenia"; exit 1
fi

echo "ETAP 07: wszystkie zestawy PASS"
