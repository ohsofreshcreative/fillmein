#!/usr/bin/env bash
#
# Etap 06 — konfigurator sekcji i pól na lokalnym LocalWP.
#
# 1. Logika: operacje SectionService, walidacja i odporność na import z Figmy (transakcja wycofywana).
# 2. Uprawnienia, HTTP (IDOR, CSRF, 422) i skutki żądań na danych testowych etapu 03 (sprzątane na końcu).
#
# Użycie: tests/stage06/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

echo "### 1/2 Logika"
fmiwp eval-file "$THEME/tests/stage06/logic.php" || STATUS=1

echo
echo "### 2/2 Uprawnienia, HTTP, skutki"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
fmiwp eval-file "$THEME/tests/stage06/seed.php" || STATUS=1
fmiwp eval-file "$THEME/tests/stage06/permissions.php" || STATUS=1
bash "$THEME/tests/stage06/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage06/effects.php" || STATUS=1
fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
rm -f /tmp/fmi-stage06-ids.json

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 06: wystąpiły niepowodzenia"; exit 1
fi

echo "ETAP 06: wszystkie zestawy PASS"
