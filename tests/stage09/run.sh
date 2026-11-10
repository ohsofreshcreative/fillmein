#!/usr/bin/env bash
#
# Etap 09 — zdjęcia, galerie i bezpieczne serwowanie na lokalnym LocalWP.
#
# 1. Logika (baza w transakcji wycofywanej; pliki trafiają do manifestu sprzątania).
# 2. Uprawnienia, HTTP, skutki. Dane testowe są sprzątane na końcu, pliki wyłącznie z manifestu.
#
# Tokeny testowe leżą tylko w pliku tymczasowym (0600), usuwanym w trap EXIT, i nie są wypisywane.
#
# Użycie: tests/stage09/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

cleanup() {
  fmiwp eval-file "$THEME/tests/stage09/cleanup.php" || echo "UWAGA: sprzątanie plików zgłosiło błąd."
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage09-ids.json
}

fmiwp eval-file "$THEME/tests/stage09/fixtures.php" || { echo "BLOCKED: nie udało się utworzyć plików testowych."; exit 2; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT

echo "### 1/2 Logika"
fmiwp eval-file "$THEME/tests/stage09/logic.php" || STATUS=1

echo
echo "### 2/2 Uprawnienia, HTTP, skutki"
fmiwp eval-file "$THEME/tests/stage09/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }
fmiwp eval-file "$THEME/tests/stage09/permissions.php" || STATUS=1
bash "$THEME/tests/stage09/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage09/effects.php" || STATUS=1

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 09: wystąpiły niepowodzenia"; exit 1
fi

echo "ETAP 09: wszystkie zestawy PASS"
