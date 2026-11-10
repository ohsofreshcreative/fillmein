#!/usr/bin/env bash
# Etap 10 — testy HTTP synchronizacji, przeglądu treści i dziennika. Token nie trafia na wyjście.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage10-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b); PAGE=$(json "$MANIFEST" pages.a); PAGE_B=$(json "$MANIFEST" pages.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
TOKEN=$(json "$IDS" token); CUT=$(json "$IDS" pages.cut); RISK=$(json "$IDS" pages.risk); VALUE=$(json "$IDS" value)
IMPORT="$SITE/app/projects/$PA/pages/$PAGE/figma/import"
ACTIVITY="$SITE/app/projects/$PA/activity"
REVIEW="$SITE/app/projects/$PA/review"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -q -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
hasntf() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono sekret"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
trap 'rm -f "$JAR"' EXIT

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$@"; }
admin() { curl -s --max-time 40 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$@"; }
meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
print() { printf '%s' "$1" | grep -o 'name="fingerprint" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
post() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" -H "Referer: $2" -X POST "${@:3}" "$1"; }

echo "== Podgląd zmian =="
HTML=$(admin "$IMPORT")
CSRF=$(meta "$HTML")
FP=$(print "$HTML")
has "Wybór zmian" "$HTML" 'name="selected\[\]"'
has "Pole należy do formularza" "$HTML" 'form="import-form"'
has "Wybór etykiety" "$HTML" 'value="f:10:301"'
has "Wybór nowej warstwy" "$HTML" 'value="f:10:306"'
has "Wybór przeniesionej sekcji" "$HTML" 'value="s:10:600"'
has "Wybór listy" "$HTML" 'value="c:10:405"'
has "Wybór archiwum" "$HTML" 'value="a:field:'
has "Licznik przeniesionych" "$HTML" 'Przeniesione: 1'
hasnt "Bez ostrzeżenia o przycięciu" "$HTML" 'Frame pobrano tylko częściowo'
hasnt "Bez wyboru dla pola bez zmian" "$HTML" 'value="f:10:201"'

CUT_HTML=$(admin "$SITE/app/projects/$PA/pages/$CUT/figma/import")
has "Frame przycięty: ostrzeżenie" "$CUT_HTML" 'Frame pobrano tylko częściowo'
has "Frame przycięty: archiwum 0" "$CUT_HTML" 'Do archiwum: 0'
hasnt "Frame przycięty: bez wyboru archiwum" "$CUT_HTML" 'value="a:'

RISK_HTML=$(admin "$SITE/app/projects/$PA/pages/$RISK/figma/import")
has "Zmiana ryzykowna oznaczona" "$RISK_HTML" 'Zmiana ryzykowna'
has "Wybór typu" "$RISK_HTML" 'value="t:f:10:201"'
TYPE_INPUT=$(printf '%s' "$RISK_HTML" | tr '\n' ' ' | grep -o '<input[^>]*value="t:f:10:201"[^>]*>')
hasnt "Zmiana ryzykowna domyślnie odznaczona" "$TYPE_INPUT" 'checked'

echo "== Zastosowanie =="
check "Bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -d "fingerprint=$FP" "$IMPORT")"
check "Zły format odcisku → 302" 302 "$(post "$IMPORT" "$IMPORT" -d 'fingerprint=abc')"
check "Pracownik spoza projektu: podgląd → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$IMPORT")"
check "Podstrona B pod projektem A → 404" 404 "$(code -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_B/figma/import")"
check "Przestarzały odcisk → 302" 302 "$(post "$IMPORT" "$IMPORT" -d "selection=1&fingerprint=$(printf 'a%.0s' {1..64})&selected[]=f:10:301")"
has "Komunikat o przestarzałym podglądzie" "$(admin "$IMPORT")" 'zmieniła się'
check "Pusty wybór → 302" 302 "$(post "$IMPORT" "$IMPORT" -d "selection=1&fingerprint=$FP")"
has "Komunikat o pustym wyborze" "$(admin "$IMPORT")" 'co najmniej jedną'
check "Zastosowanie wszystkich" 302 "$(post "$IMPORT" "$IMPORT" -d "selection=1&fingerprint=$FP" -d 'selected[]=f:10:301' -d 'selected[]=f:10:306' -d 'selected[]=s:10:600' -d 'selected[]=c:10:405' --data-urlencode "selected[]=$(printf '%s' "$HTML" | grep -o 'value="a:field:[0-9]*"' | sed 's/value="//;s/"$//')")"
AFTER=$(admin "$IMPORT")
has "Komunikat wyniku" "$AFTER" 'Import zatwierdzony'
has "Po zastosowaniu: brak zmian" "$AFTER" 'Brak zmian'
hasnt "Po zastosowaniu: bez wyboru" "$AFTER" 'name="selected\[\]"'

echo "== Dziennik =="
LOG=$(admin "$ACTIVITY")
has "Dziennik: wpis synchronizacji" "$LOG" 'Zsynchronizowano sekcje i pola z Figmą'
has "Dziennik: podsumowanie diffu" "$LOG" 'do archiwum 1'
has "Dziennik: stronicowanie" "$LOG" 'aria-label="Paginacja"'
has "Dziennik: zapis treści" "$(admin "$ACTIVITY?typ=tresc")" 'Zapisano treść'
hasnt "Filtr typu" "$(admin "$ACTIVITY?typ=tresc")" 'Zsynchronizowano'
hasnt "Filtr klienta" "$(admin "$ACTIVITY?aktor=klient")" 'Zsynchronizowano'
has "Filtr podstrony" "$(admin "$ACTIVITY?strona=$PAGE")" 'Zsynchronizowano'
hasnt "Filtr podstrony wyklucza inne" "$(admin "$ACTIVITY?strona=$RISK")" 'Treść od klienta'
hasnt "Filtr dat" "$(admin "$ACTIVITY?do=2000-01-01")" 'Zsynchronizowano'
has "Zły format daty ignorowany" "$(admin "$ACTIVITY?od=abc")" 'Zsynchronizowano'
check "Druga strona" 200 "$(code -H "Cookie: $C_ADMIN" "$ACTIVITY?page=2")"
check "Pracownik projektu" 200 "$(code -H "Cookie: $C_MEMBER" "$ACTIVITY")"
check "Spoza projektu → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$ACTIVITY")"
check "Gość → logowanie" 302 "$(code "$ACTIVITY")"
check "Klient → brak trasy" 302 "$(code "$SITE/p/$TOKEN/activity")"
check "Zmiana parametru projektu bez przypisania → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$SITE/app/projects/$PB/activity")"
hasntf "Dziennik bez tokenu" "$LOG" "$TOKEN"

echo "== Przegląd treści =="
LIST=$(admin "$REVIEW")
has "Lista pól" "$LIST" 'Treści projektu'
has "Status pustego" "$LIST" '>Puste<\|Puste:'
has "Stronicowanie przeglądu" "$LIST" 'aria-label="Paginacja"'
FILLED=$(admin "$REVIEW?status=filled")
has "Filtr statusu" "$FILLED" 'Tytuł klienta'
hasnt "Filtr autora" "$(admin "$REVIEW?autor=klient")" 'Tytuł klienta'
has "Wartość z autorem" "$FILLED" 'Oznacz do poprawy'
RCSRF=$(meta "$LIST")
RV=$(printf '%s' "$FILLED" | grep -o "/review/[0-9]*\"" | head -1 | sed 's/[^0-9]//g')
CSRF=$RCSRF
check "Oznaczenie bez komentarza → 302" 302 "$(post "$REVIEW/$RV" "$REVIEW" -d 'note=')"
check "Oznaczenie" 302 "$(post "$REVIEW/$RV" "$REVIEW" --data-urlencode 'note=<b>Popraw</b> tytuł')"
MARKED=$(admin "$REVIEW?status=review")
has "Status do poprawy" "$MARKED" 'Zdejmij oznaczenie'
has "Komentarz zabezpieczony" "$MARKED" '&lt;b&gt;Popraw'
check "Bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -d 'note=x' "$REVIEW/$RV")"
check "Obcy projekt → 404" 404 "$(post "$SITE/app/projects/$PB/review/$RV" "$REVIEW" --data-urlencode 'note=x')"
check "Spoza projektu → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Cookie: $C_OUTSIDER" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CSRF" -c "$JAR" -b "$JAR" -d 'note=x' "$REVIEW/$RV")"
check "Przegląd spoza projektu → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$REVIEW")"
check "Nieistniejąca wartość → 404" 404 "$(post "$REVIEW/999999999" "$REVIEW" --data-urlencode 'note=x')"
check "Zdjęcie" 302 "$(post "$REVIEW/$RV/clear" "$REVIEW")"
hasnt "Po zdjęciu brak statusu" "$(admin "$REVIEW?status=review")" 'Zdejmij oznaczenie'
check "Ponowne oznaczenie do sprawdzenia skutków" 302 "$(post "$REVIEW/$RV" "$REVIEW" --data-urlencode 'note=Do sprawdzenia')"
HTML_CLIENT=$(curl -s --max-time 25 "$SITE/p/$TOKEN")
hasnt "Komentarz niewidoczny dla klienta" "$HTML_CLIENT" 'Do sprawdzenia'

echo
echo "HTTP etapu 10: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
