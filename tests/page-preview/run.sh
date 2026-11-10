#!/usr/bin/env bash
#
# Podgląd podstrony w panelu: dostęp, obcy projekt, treść, braki, ucieczka HTML. Dane testowe z etapu 03.
#
# Użycie: bash tests/page-preview/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage03/cookies.php" >/dev/null
fmiwp eval-file "$THEME/tests/page-preview/seed.php" || { echo "BLOCKED: nie udało się zasiać treści."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents("/tmp/fmi-stage03-fixtures.json"),true); foreach(explode(".",$argv[1]) as $k){$m=$m[$k];} echo $m;' "$1"; }

SITE=$(json site); PA=$(json projects.a); PB=$(json projects.b); PAGE_A=$(json pages.a); PAGE_B=$(json pages.b)
C_ADMIN=$(json cookies.admin); C_MEMBER=$(json cookies.member); C_OUTSIDER=$(json cookies.outsider)
URL="$SITE/app/projects/$PA/pages/$PAGE_A/preview"
PASS=0; FAIL=0

check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
code() { if [ "$1" = "-" ]; then curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$2"; else curl -s -o /dev/null -w '%{http_code}' --max-time 25 -H "Cookie: $1" "$2"; fi; }
has() { if grep -qF -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

echo "== Dostęp =="
check "Gość → logowanie" 302 "$(code - "$URL")"
check "Administrator" 200 "$(code "$C_ADMIN" "$URL")"
check "Członek projektu" 200 "$(code "$C_MEMBER" "$URL")"
check "Pracownik bez przypisania → 404" 404 "$(code "$C_OUTSIDER" "$URL")"
check "Podstrona innego projektu → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_B/preview")"

echo "== Treść =="
HTML=$(curl -s --max-time 25 -H "Cookie: $C_ADMIN" "$URL")
has "Wypełnione pole" "$HTML" "Pole wypełnione"
has "Treść klienta" "$HTML" "Treść klienta"
has "Autor i data" "$HTML" "$(date +%d.%m.%Y)"
has "Brak oznaczony" "$HTML" "Brak treści"
hasnt "HTML klienta jest escapowany" "$HTML" "<script>alert(1)"
hasnt "Bez pól ukrytych przed klientem" "$HTML" "Pole ukryte przed klientem"
LIST=$(curl -s --max-time 25 -H "Cookie: $C_MEMBER" "$SITE/app/projects/$PA")
has "Lista podstron: link do podglądu" "$LIST" "/pages/$PAGE_A/preview"

has "Edycja przy sekcji" "$HTML" "/pages/$PAGE_A/content#sekcja-"
has "Treść w zielonej ramce" "$HTML" "border-success-200"
EDIT=$(curl -s --max-time 25 -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_A/content")
has "Edytor: powrót do podglądu" "$EDIT" "/pages/$PAGE_A/preview"
has "Edytor: pole do zapisu" "$EDIT" "clientField"
has "Edytor: okno powiększenia" "$EDIT" "x-ref=\"lightbox\""

echo
echo "PODGLĄD PODSTRONY: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
