#!/usr/bin/env bash
#
# Krok 0 — testy regresyjne poprawek P0 na lokalnym LocalWP.
#
# 1. Logika (transakcja wycofywana): truncated, ID instancji, duplikowanie.
# 2. Sesja klienta: token nie trafia do pliku sesji (tymczasowy projekt, sprzątany).
# 3. HTTP: POST pracownika bez przypisania → 404 (dane testowe etapu 03, sprzątane).
# 4. Skan plików do dodania w Git pod kątem sekretów (tylko ścieżki i liczby).
#
# Użycie: tests/step00/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

echo "### 1/4 Logika"
fmiwp eval-file "$THEME/tests/step00/logic.php" || STATUS=1

echo
echo "### 2/4 Sesja klienta"
fmiwp eval-file "$THEME/tests/step00/client-session.php" || STATUS=1

echo
echo "### 3/4 HTTP (autoryzacja POST)"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
bash "$THEME/tests/step00/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."

echo
echo "### 4/4 Skan sekretów (pliki do dodania w Git)"
php "$THEME/tests/step00/scan-secrets.php" || STATUS=1

echo
if [ "$STATUS" -eq 0 ]; then
  echo "KROK 00: wszystkie zestawy PASS"
else
  echo "KROK 00: wystąpiły niepowodzenia"
fi
exit "$STATUS"
