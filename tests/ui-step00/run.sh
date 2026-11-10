#!/usr/bin/env bash
#
# Krok 0 — audyt UI widoku importu z Figmy (Playwright + axe-core z /tmp/fmi-ui-audit).
#
# Dane testowe etapu 03 + zasiew przyciętego drzewa (tests/ui-step00/seed.php), sprzątane w trap EXIT.
# Kod: 0 — audyt PASS, 1 — FAIL, 2 — BLOCKED (brak narzędzi lub danych).
#
# Użycie: tests/ui-step00/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

TOOLS=/tmp/fmi-ui-audit/node_modules
AUDIT_DIR=/tmp/fmi-ui-audit-step00

for file in "$TOOLS/playwright/index.mjs" "$TOOLS/axe-core/axe.min.js"; do
  [ -f "$file" ] || { echo "BLOCKED: brak $file (bez instalacji pakietów w tym etapie)."; exit 2; }
done

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
}

echo "### 1/2 Dane testowe"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/ui-step00/seed.php" || { echo "BLOCKED: zasiew stanu truncated nie powiódł się."; exit 2; }
fmiwp eval-file "$THEME/tests/stage03/cookies.php" >/dev/null || { echo "BLOCKED: nie udało się odświeżyć ciasteczek."; exit 2; }

echo
echo "### 2/2 Audyt UI"
mkdir -p "$AUDIT_DIR" && cp "$THEME/tests/ui-step00/audit.mjs" "$AUDIT_DIR/audit.mjs" || { echo "BLOCKED: nie można przygotować $AUDIT_DIR."; exit 2; }
bash "$THEME/tests/bin/ui-audit.sh" "$AUDIT_DIR"
STATUS=$?

echo
[ "$STATUS" -eq 0 ] && echo "KROK 0 — AUDYT UI: PASS" || echo "KROK 0 — AUDYT UI: FAIL (kod $STATUS)"
[ "$STATUS" -le 1 ] && exit "$STATUS"
exit 2
