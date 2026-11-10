#!/usr/bin/env bash
#
# Etap 04 — testy integracji Figma na lokalnym LocalWP.
#
# 1. Logika na fixture (parser, klient API, import frame'u, cache, podglądy) — bez sieci.
# 2. HTTP tras Figmy na danych testowych etapu 03 (zasiew cache przez atrapę API).
# 3. Test LIVE — tylko z tokenem FMI_FIGMA_TOKEN i FMI_FIGMA_LIVE_URL; bez nich NOT_RUN (kod 3).
#
# Użycie:
#   tests/stage04/run.sh
#   FMI_FIGMA_LIVE_URL='https://www.figma.com/design/<klucz>/<nazwa>?node-id=1-2' tests/stage04/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

STATUS=0

# Testy sprawdzają zachowanie bez dostępu do Figmy, więc prawdziwe połączenie OAuth odkładamy na czas przebiegu.
fmiwp eval-file "$THEME/tests/figma-oauth/hold.php" save || { echo "BLOCKED: nie udało się odłożyć połączenia Figmy."; exit 2; }
trap 'fmiwp eval-file "$THEME/tests/figma-oauth/hold.php" restore || echo "UWAGA: przywróć połączenie Figmy."' EXIT

echo "### 1/3 Logika na fixture"
fmiwp eval-file "$THEME/tests/stage04/figma.php" || STATUS=1

echo
echo "### 2/3 HTTP"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
fmiwp eval-file "$THEME/tests/stage04/seed.php" || STATUS=1
bash "$THEME/tests/stage04/http.sh" || STATUS=1
fmiwp eval-file "$THEME/tests/stage04/cleanup.php" || echo "UWAGA: nie usunięto plików podglądów testowych."
fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."

echo
echo "### 3/3 LIVE (Figma API)"
fmiwp eval-file "$THEME/tests/stage04/live.php"
LIVE=$?

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 04: wystąpiły niepowodzenia"; exit 1
fi

if [ "$LIVE" -eq 3 ]; then
  echo "ETAP 04: fixture i HTTP PASS; test LIVE NOT_RUN (brak tokenu lub FMI_FIGMA_LIVE_URL)"; exit 3
fi

[ "$LIVE" -eq 0 ] && echo "ETAP 04: wszystkie zestawy PASS (w tym LIVE)" || echo "ETAP 04: test LIVE FAIL"
exit "$LIVE"
