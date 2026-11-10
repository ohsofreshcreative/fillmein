#!/usr/bin/env bash
#
# Etap 08 — autozapis, konflikty, historia i postęp na lokalnym LocalWP.
#
# 1. Logika (transakcja wycofywana).
# 2. Współbieżność: dwa niezależne procesy WP zapisują z tą samą wersją bazową.
# 3. Uprawnienia, HTTP, skutki. Dane testowe są sprzątane na końcu.
#
# Tokeny testowe leżą tylko w pliku tymczasowym (0600), usuwanym w trap EXIT, i nie są wypisywane.
#
# Użycie: tests/stage08/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

echo "### 1/3 Logika"
fmiwp eval-file "$THEME/tests/stage08/logic.php" || STATUS=1

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage08-ids.json /tmp/fmi-stage08-race.json /tmp/fmi-stage08-race-*.json
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage08/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }

echo
echo "### 2/3 Współbieżność (dwa procesy)"
fmiwp eval-file "$THEME/tests/stage08/race-prepare.php" || { echo "BLOCKED: przygotowanie wyścigu nie powiodło się."; exit 2; }
fmiwp eval-file "$THEME/tests/stage08/race-worker.php" a > /tmp/fmi-stage08-race-a.log 2>&1 &
WORKER_A=$!
fmiwp eval-file "$THEME/tests/stage08/race-worker.php" b > /tmp/fmi-stage08-race-b.log 2>&1 &
WORKER_B=$!
wait "$WORKER_A" || STATUS=1
wait "$WORKER_B" || STATUS=1
rm -f /tmp/fmi-stage08-race-a.log /tmp/fmi-stage08-race-b.log
fmiwp eval-file "$THEME/tests/stage08/concurrency.php" || STATUS=1

echo
echo "### 3/3 Uprawnienia, HTTP, skutki"
fmiwp eval-file "$THEME/tests/stage08/permissions.php" || STATUS=1
bash "$THEME/tests/stage08/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage08/effects.php" || STATUS=1

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 08: wystąpiły niepowodzenia"; exit 1
fi

echo "ETAP 08: wszystkie zestawy PASS"
