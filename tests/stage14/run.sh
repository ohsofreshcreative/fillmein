#!/usr/bin/env bash
#
# Etap 14 — końcowe QA. Liczniki z kroku 1 są brane przed utworzeniem danych testowych, więc porównanie na końcu
# obejmuje cały przebieg: E2E, macierz, audyt i sprzątanie.
#
# Kod: 0 — PASS, 1 — FAIL, 2 — BLOCKED. Wyniki: /tmp/fmi-stage14-matrix.md, /tmp/fmi-stage14-ui.md.
#
# Użycie: tests/stage14/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

# wp.sh db-backup potrzebuje mysqldump, którego PATH sesji nie zawiera.
if ! command -v mysqldump >/dev/null 2>&1; then
  for dir in "$HOME/Library/Application Support/Local/lightning-services"/mysql-*/bin/darwin-arm64/bin; do
    [ -x "$dir/mysqldump" ] && export PATH="$PATH:$dir"
  done
fi

TOOLS=/tmp/fmi-ui-audit/node_modules
AUDIT_DIR=/tmp/fmi-ui-audit-stage14
STATUS=0
CLEANED=""

run_php() { fmiwp eval-file "$THEME/tests/$1" "${@:2}"; }

cleanup() {
  [ -n "$CLEANED" ] && return
  CLEANED=1
  run_php stage14/cleanup.php || echo "UWAGA: sprzątanie Figmy i dziennika zgłosiło błąd."
  run_php stage09/cleanup.php || echo "UWAGA: sprzątanie plików zgłosiło błąd."
  run_php stage11/cleanup.php || echo "UWAGA: sprzątanie eksportów zgłosiło błąd."
  run_php stage03/teardown.php || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage11-ids.json /tmp/fmi-stage14-ids.json /tmp/fmi-stage14-e2e.json /tmp/fmi-stage14-plan.tsv /tmp/fmi-stage14-hash
}

finish() {
  cleanup
  rm -f /tmp/fmi-stage12-before14.json /tmp/fmi-stage12-after14.json
}

echo "### 1/7 Skan sekretów"
bash "$THEME/tests/stage14/secrets.sh" || STATUS=1

echo
echo "### 2/7 Liczniki wyjściowe"
run_php stage12/counts.php before14 || { echo "BLOCKED: brak liczników."; exit 2; }
trap finish EXIT

echo
echo "### 3/7 Dane testowe"
run_php stage09/fixtures.php || { echo "BLOCKED: nie udało się utworzyć plików testowych."; exit 2; }
run_php stage03/setup.php || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
run_php stage11/seed.php || { echo "BLOCKED: zasiew etapu 11 nie powiódł się."; exit 2; }
run_php stage14/seed.php || { echo "BLOCKED: zasiew etapu 14 nie powiódł się."; exit 2; }
run_php stage14/security.php plan || { echo "BLOCKED: brak planu tras."; exit 2; }

echo
echo "### 4/7 E2E: pracownik i klient"
bash "$THEME/tests/stage14/e2e.sh" || STATUS=1

echo
echo "### 5/7 Audyt UI"
for file in "$TOOLS/playwright/index.mjs" "$TOOLS/axe-core/axe.min.js"; do
  [ -f "$file" ] || { echo "BLOCKED: brak $file."; exit 2; }
done
mkdir -p "$AUDIT_DIR" && cp "$THEME/tests/stage14/audit.mjs" "$AUDIT_DIR/audit.mjs" || { echo "BLOCKED: nie można przygotować audytu."; exit 2; }
bash "$THEME/tests/bin/ui-audit.sh" "$AUDIT_DIR"
CODE=$?
[ "$CODE" -eq 2 ] && exit 2
[ "$CODE" -eq 0 ] || STATUS=1

echo
echo "### 6/7 Macierz bezpieczeństwa"
run_php stage14/security.php hash > /tmp/fmi-stage14-hash
bash "$THEME/tests/stage14/security.sh" || STATUS=1

echo
echo "### 7/7 Stan bazy po sprzątaniu"
cleanup
run_php stage12/counts.php after14 || STATUS=1
run_php stage12/compare.php before14 after14 || STATUS=1

echo
[ "$STATUS" -eq 0 ] && echo "ETAP 14: wszystkie zestawy PASS" || echo "ETAP 14: wystąpiły niepowodzenia"
exit "$STATUS"
