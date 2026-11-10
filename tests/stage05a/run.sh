#!/usr/bin/env bash
#
# Etap 05a — ID warstw Figmy do 255 znaków i wczytywanie sekretów testowych na lokalnym LocalWP.
#
# 0. Migracja w izolowanej bazie SQLite w pamięci (up/down, indeksy, odmowa down() przy długich ID).
# 1. Schemat: 6 kolumn VARCHAR(255), indeksy zachowane (+ wydruk SHOW INDEX do raportu).
# 2. Logika: import danych z ID 200 znaków w transakcji wycofywanej, odmowa down() przy długich wartościach.
# 3. LIVE — tylko z FMI_FIGMA_TOKEN i FMI_FIGMA_LIVE_URL (env.sh wczytuje je z .ai-workflow/secrets/);
#    bez nich NOT_RUN (kod 3).
# 4. Sekrety: w wyjściu testów nie ma tokenu Figmy ani linku LIVE.
#
# Kod 0 tylko gdy wszystko PASS, w tym LIVE.
#
# Użycie: tests/stage05a/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

LOG="$(mktemp "${TMPDIR:-/tmp}/fmi-stage05a.XXXXXX")"
trap 'rm -f "$LOG"' EXIT

STATUS=0

echo "### 1/5 Migracja (SQLite w pamięci)"
php "$THEME/tests/stage05a/migration.php" 2>&1 | tee -a "$LOG"
[ "${PIPESTATUS[0]}" -eq 0 ] || STATUS=1

echo
echo "### 2/5 Schemat"
fmiwp eval-file "$THEME/tests/stage05a/schema.php" 2>&1 | tee -a "$LOG"
[ "${PIPESTATUS[0]}" -eq 0 ] || STATUS=1

echo
echo "### 3/5 Logika (ID 200 znaków, down())"
fmiwp eval-file "$THEME/tests/stage05a/logic.php" 2>&1 | tee -a "$LOG"
[ "${PIPESTATUS[0]}" -eq 0 ] || STATUS=1

echo
echo "### 4/5 LIVE (Figma API)"
fmiwp eval-file "$THEME/tests/stage05a/live.php" 2>&1 | tee -a "$LOG"
LIVE="${PIPESTATUS[0]}"

echo
echo "### 5/5 Sekrety w wyjściu"
# Wartości sekretów porównujemy bez wypisywania; grep -F -q nie drukuje dopasowań.
LEAK=0
for name in FMI_FIGMA_TOKEN FMI_FIGMA_LIVE_URL; do
  value="${!name:-}"
  if [ -n "$value" ] && grep -F -q -- "$value" "$LOG"; then
    echo "  FAIL  wyjście testów zawiera wartość $name"; LEAK=1
  else
    echo "  PASS  wyjście testów nie zawiera wartości $name"
  fi
done
[ "$LEAK" -eq 0 ] || STATUS=1

echo
if [ "$STATUS" -ne 0 ]; then
  echo "ETAP 05a: wystąpiły niepowodzenia"; exit 1
fi

if [ "$LIVE" -eq 3 ]; then
  echo "ETAP 05a: schemat i logika PASS; LIVE NOT_RUN"; exit 3
fi

[ "$LIVE" -eq 0 ] && echo "ETAP 05a: wszystkie zestawy PASS (w tym LIVE)" || echo "ETAP 05a: test LIVE FAIL"
exit "$LIVE"
