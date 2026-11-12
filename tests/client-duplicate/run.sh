#!/usr/bin/env bash
# Klient dodaje kolejną podstronę na wzór istniejącej (client_can_duplicate) i usuwa dodaną przez siebie.
# Użycie: tests/client-duplicate/run.sh

set -uo pipefail
THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage07-ids.json
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage07/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }
bash "$THEME/tests/client-duplicate/http.sh"
