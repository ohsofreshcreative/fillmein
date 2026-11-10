#!/usr/bin/env bash
#
# Etap 03 — pełny przebieg testów na lokalnym LocalWP.
#
# Kolejność ma znaczenie: testy uprawnień dezaktywują konto testowe (niszcząc jego sesje),
# więc ciasteczka do testów HTTP odświeżamy po nich. Dane testowe są zawsze sprzątane.
#
# Użycie: bash tests/stage03/run.sh
#   Socket MySQL wykrywa tests/bin/env.sh (domyślnie z sites.json Local).
#   Opcjonalnie SOCK=<ścieżka do mysqld.sock> wymusza konkretny socket (musi należeć do fillmein.local).

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

echo "### 1/5 Logika (transakcja wycofywana)"
fmiwp eval-file "$THEME/tests/stage03/logic.php" || STATUS=1

echo
echo "### 2/5 Dane testowe"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }

echo
echo "### 3/5 Uprawnienia"
fmiwp eval-file "$THEME/tests/stage03/permissions.php" || STATUS=1

fmiwp eval-file "$THEME/tests/stage03/cookies.php" >/dev/null

echo
echo "### 4/5 HTTP"
bash "$THEME/tests/stage03/http.sh" || STATUS=1

echo
echo "### 5/5 Skutki żądań POST"
fmiwp eval-file "$THEME/tests/stage03/effects.php" || STATUS=1

echo
echo "### Sprzątanie"
fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."

echo
if [ "$STATUS" -eq 0 ]; then
  echo "ETAP 03: wszystkie zestawy PASS"
else
  echo "ETAP 03: wystąpiły niepowodzenia"
fi
exit "$STATUS"
