#!/usr/bin/env bash
#
# Wspólne połączenie z Figmą: logika na atrapie HTTP oraz strona ustawień przez HTTP. Prawdziwe połączenie jest
# odkładane na czas przebiegu i przywracane w trap EXIT. Logowania w samej Figmie test nie obejmuje.
#
# Użycie: bash tests/figma-oauth/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

cleanup() {
  fmiwp eval-file "$THEME/tests/figma-oauth/cleanup.php" || echo "UWAGA: sprzątanie dziennika zgłosiło błąd."
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  fmiwp eval-file "$THEME/tests/figma-oauth/hold.php" restore || echo "UWAGA: przywróć połączenie Figmy."
}

fmiwp eval-file "$THEME/tests/figma-oauth/hold.php" save || { echo "BLOCKED: nie udało się odłożyć połączenia."; exit 2; }
trap 'fmiwp eval-file "$THEME/tests/figma-oauth/hold.php" restore' EXIT

echo "### 1/2 Logika"
fmiwp eval-file "$THEME/tests/figma-oauth/logic.php" || STATUS=1

echo
echo "### 2/2 HTTP"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
bash "$THEME/tests/figma-oauth/http.sh" || STATUS=1

echo
[ "$STATUS" -eq 0 ] && echo "FIGMA OAUTH: wszystkie zestawy PASS" || echo "FIGMA OAUTH: wystąpiły niepowodzenia"
exit "$STATUS"
