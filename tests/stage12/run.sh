#!/usr/bin/env bash
#
# Etap 12 — importer WP-CLI na stronie fillmein. Kolejność kroków jest częścią testu: liczniki z kroku 2
# są punktem odniesienia dla dry-run, importu i sprzątania.
#
# Użycie: tests/stage12/run.sh

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

STATE=/tmp/fmi-stage12-state.json
STATUS=0
RESTORED=""
BACKUP=""
WP="$THEME/tests/bin/wp.sh"

value() { php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m[$argv[2]];' "$STATE" "$1"; }
expect_code() { if [ "$2" = "$3" ]; then echo "  PASS  $1 (kod $3)"; else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; STATUS=1; fi; }
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1 — brak: $3"; STATUS=1; fi; }
counts() { fmiwp eval-file "$THEME/tests/stage12/counts.php" "$1" || STATUS=1; }
compare() { fmiwp eval-file "$THEME/tests/stage12/compare.php" "$@" || STATUS=1; }

cleanup() {
  fmiwp eval-file "$THEME/tests/stage12/rescue.php" || echo "UWAGA: awaryjne sprzątanie importu zgłosiło błąd."
  fmiwp eval-file "$THEME/tests/stage11/cleanup.php" || echo "UWAGA: sprzątanie plików zgłosiło błąd."
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
  rm -f /tmp/fmi-stage11-ids.json "$STATE" /tmp/fmi-stage12-before.json /tmp/fmi-stage12-dry.json /tmp/fmi-stage12-applied.json \
    /tmp/fmi-stage12-after.json /tmp/fmi-stage12-changed.zip /tmp/fmi-stage12-broken.json /tmp/fmi-stage12-version.json \
    /tmp/fmi-stage12-tampered.zip /tmp/fmi-stage12-meta.json
}

echo "### 1/9 Dane i eksport"
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage11/seed.php" || { echo "BLOCKED: zasiew nie powiódł się."; exit 2; }
fmiwp eval-file "$THEME/tests/stage12/seed.php" || { echo "BLOCKED: eksport testowy nie powiódł się."; exit 2; }
ZIP=$(value zip); JSON=$(value json)

echo
echo "### 2/9 Liczniki przed importem"
counts before

echo
echo "### 3/9 Dry-run i odmowy (bez zapisu)"
OUT=$(bash "$WP" acorn fmi:import "$ZIP" 2>&1); CODE=$?
expect_code "Dry-run ZIP" 0 "$CODE"
has "Dry-run: plan" "$OUT" 'Dry-run (bez zapisu)'
printf '%s\n' "$OUT" | grep -E 'Media|Grupy|Pola ACF|Strony|Wartości|Razem'
OUT=$(bash "$WP" acorn fmi:import "$JSON" 2>&1); CODE=$?
expect_code "Dry-run JSON z paczką obok" 0 "$CODE"
bash "$WP" acorn fmi:import "$ZIP" --apply >/dev/null 2>&1; expect_code "--apply bez --backup" 4 $?
bash "$WP" acorn fmi:import:cleanup --apply >/dev/null 2>&1; expect_code "cleanup --apply bez --backup" 4 $?
bash "$WP" acorn fmi:import "$THEME/nie-ma.zip" >/dev/null 2>&1; expect_code "Brak pliku" 4 $?
bash "$WP" acorn fmi:import /tmp/fmi-stage12-broken.json >/dev/null 2>&1; expect_code "Eksport niezgodny ze schematem" 4 $?
bash "$WP" acorn fmi:import /tmp/fmi-stage12-version.json >/dev/null 2>&1; expect_code "Nieobsługiwana schema_version" 4 $?
bash "$WP" acorn fmi:import /tmp/fmi-stage12-tampered.zip >/dev/null 2>&1; expect_code "Podmieniony plik w paczce" 4 $?
OLD=$(mktemp "${TMPDIR:-/tmp}/fmi-old.XXXXXX"); printf 'x' > "$OLD"; touch -t 202001010000 "$OLD"
bash "$WP" acorn fmi:import "$ZIP" --apply --backup="$OLD" >/dev/null 2>&1; expect_code "Przeterminowany backup" 4 $?
rm -f "$OLD"
fmiwp eval-file "$THEME/tests/stage12/refusals.php" || STATUS=1
counts dry
compare before dry

echo
echo "### 4/9 Backup i pierwszy import"
OUT=$(bash "$WP" db-backup pre-12 2>&1); CODE=$?
expect_code "Backup" 0 "$CODE"
BACKUP=$(printf '%s\n' "$OUT" | sed -n 's/^Plik: //p')
[ -s "$BACKUP" ] || { echo "BLOCKED: brak pliku backupu."; exit 2; }
echo "  Backup: $BACKUP"
fmiwp eval-file "$THEME/tests/stage12/apply.php" || STATUS=1

echo
echo "### 5/9 Asercje get_field"
fmiwp eval-file "$THEME/tests/stage12/verify.php" || STATUS=1
counts applied
fmiwp eval-file "$THEME/tests/stage12/isolated.php" || STATUS=1

echo
echo "### 6/9 Drugi import przez nakładkę: 0 zmian"
OUT=$(bash "$WP" acorn fmi:import "$ZIP" --apply --backup="$BACKUP" 2>&1); CODE=$?
expect_code "Drugi --apply" 0 "$CODE"
has "Raport: 0 utworzonych, 0 zmienionych" "$OUT" 'Razem: utworzyć 0, zmienić 0, usunąć 0'
printf '%s\n' "$OUT" | grep -E 'Media|Grupy|Pola ACF|Strony|Wartości|Razem'
counts after
compare applied after

echo
echo "### 7/9 Zmiana jednej wartości"
fmiwp eval-file "$THEME/tests/stage12/change.php" || STATUS=1

echo
echo "### 8/9 Sprzątanie"
OUT=$(bash "$WP" acorn fmi:import:cleanup 2>&1); CODE=$?
expect_code "Cleanup dry-run" 0 "$CODE"
printf '%s\n' "$OUT" | grep -E 'attachment|page|acf-|Razem'
counts after
compare applied after
OUT=$(bash "$WP" acorn fmi:import:cleanup --source="$(value source)" --apply --backup="$BACKUP" 2>&1); CODE=$?
expect_code "Cleanup --apply" 0 "$CODE"
printf '%s\n' "$OUT" | grep -E 'attachment|page|acf-|Razem'
OUT=$(bash "$WP" acorn fmi:import:cleanup 2>&1)
has "Po sprzątaniu nic nie zostało" "$OUT" 'Razem: 0'
counts after
if fmiwp eval-file "$THEME/tests/stage12/compare.php" before after; then
  RESTORED=1
else
  STATUS=1
  echo "BLOCKED: sprzątanie nie przywróciło liczników. Backup do odtworzenia przez właściciela: $BACKUP"
  exit 2
fi

echo
echo "### 9/9 Pliki po sprzątaniu"
fmiwp eval-file "$THEME/tests/stage12/files.php" || STATUS=1

echo
[ "$STATUS" -eq 0 ] && echo "ETAP 12: wszystkie zestawy PASS" || echo "ETAP 12: wystąpiły niepowodzenia"
exit "$STATUS"
