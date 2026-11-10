#!/usr/bin/env bash
#
# Etap 11 — mapowanie ACF i wersjonowany eksport na lokalnym LocalWP.
#
# Dane testowe etapu 03 + zasiew tests/stage11/seed.php; pliki i dane sprzątane w trap EXIT.
#
# Użycie: tests/stage11/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

cleanup() {
  fmiwp eval-file "$THEME/tests/stage11/cleanup.php" || echo "UWAGA: sprzątanie plików zgłosiło błąd."
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage11-ids.json
}

echo "### 1/2 Dane testowe"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage11/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }

echo
echo "### 2/2 Logika, walidator, uprawnienia, HTTP, skutki"
fmiwp eval-file "$THEME/tests/stage11/logic.php" || STATUS=1
fmiwp eval-file "$THEME/tests/stage11/validator.php" || STATUS=1
fmiwp eval-file "$THEME/tests/stage11/permissions.php" || STATUS=1
bash "$THEME/tests/stage11/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage11/effects.php" || STATUS=1
fmiwp eval-file "$THEME/tests/stage11/content.php" || STATUS=1
bash "$THEME/tests/stage11/content-http.sh" || STATUS=1

echo
[ "$STATUS" -eq 0 ] && echo "ETAP 11: wszystkie zestawy PASS" || echo "ETAP 11: wystąpiły niepowodzenia"
exit "$STATUS"
