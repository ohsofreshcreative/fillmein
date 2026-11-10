#!/usr/bin/env bash
# Etap 05 — testy HTTP podglądu i importu z Figmy na działającym LocalWP (http://fillmein.local).
# Wymaga manifestu z tests/stage03/setup.php i zasiewu tests/stage05/seed.php.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
[ -f "$MANIFEST" ] || { echo "BLOCKED: brak $MANIFEST"; exit 2; }

json() { php -r '$m=json_decode(file_get_contents("/tmp/fmi-stage03-fixtures.json"),true); foreach(explode(".",$argv[1]) as $k){$m=$m[$k];} echo $m;' "$1"; }

SITE=$(json site)
PA=$(json projects.a); PB=$(json projects.b)
PAGE_A=$(json pages.a); PAGE_B=$(json pages.b)
C_ADMIN=$(json cookies.admin); C_MEMBER=$(json cookies.member); C_OUTSIDER=$(json cookies.outsider)
IMPORT="$SITE/app/projects/$PA/pages/$PAGE_A/figma/import"
RULES="$SITE/app/projects/$PA/pages/$PAGE_A/figma/rules"

PASS=0; FAIL=0
code() { if [ "$1" = "-" ]; then curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$2"; else curl -s -o /dev/null -w '%{http_code}' --max-time 25 -H "Cookie: $1" "$2"; fi; }
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
# Here-string zamiast potoku: przy `pipefail` wczesne wyjście `grep -q` przerywa printf (SIGPIPE) na dużym HTML.
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -q -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
token() { printf '%s' "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
fingerprint() { printf '%s' "$1" | grep -o 'name="fingerprint" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

echo "== Dostęp do podglądu importu =="
check "Gość → przekierowanie na logowanie" 302 "$(code - "$IMPORT")"
check "Pracownik bez przypisania → 404" 404 "$(code "$C_OUTSIDER" "$IMPORT")"
check "Przypisany pracownik → 200" 200 "$(code "$C_MEMBER" "$IMPORT")"
check "Administrator → 200" 200 "$(code "$C_ADMIN" "$IMPORT")"
check "Podstrona B pod projektem A → 404 (scopeBindings)" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_B/figma/import")"
check "Podstrona A pod projektem B → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PB/pages/$PAGE_A/figma/import")"

B_HTML=$(curl -s --max-time 25 -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PB/pages/$PAGE_B/figma/import")
has "Podstrona bez frame'u: czytelny komunikat" "$B_HTML" "Najpierw pobierz frame"

echo "== Treść podglądu =="
JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
HTML=$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")
has "Nagłówek strony" "$HTML" "Import z Figmy"
has "Sekcja Hero" "$HTML" ">Hero<"
has "Repeater kart" "$HTML" "Karty"
has "Repeater osób" "$HTML" "Lista: Osoba"
has "Typ pola: link" "$HTML" "Link (tekst i adres)"
has "Uzasadnienie typu" "$HTML" "Lista punktowana lub numerowana"
has "Licznik nowych elementów" "$HTML" "Nowe: 29"
has "Lista wykluczonych (8)" "$HTML" "Wykluczone warstwy (8)"
has "Powód: stopka" "$HTML" "Stopka"
has "Powód: ukryta warstwa" "$HTML" "Warstwa ukryta w Figmie"
has "Przycisk „Przywróć”" "$HTML" "Przywróć"
has "Escaping tekstu z Figmy" "$HTML" "&lt;script&gt;alert(1)&lt;/script&gt;"
hasnt "Brak surowego <script> z Figmy" "$HTML" "<script>alert(1)"
hasnt "Brak tokenu Figmy w HTML" "$HTML" "figd_"
FP=$(fingerprint "$HTML"); TOKEN=$(token "$HTML")
check "Odcisk podglądu w formularzu (64 znaki hex)" 1 "$(printf '%s' "$FP" | grep -cE '^[a-f0-9]{64}$')"

EDIT=$(curl -s --max-time 25 -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")
has "Edycja podstrony: link do analizy" "$EDIT" "Analiza i import pól"

echo "== Zatwierdzenie importu (POST) =="
post() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "Referer: $IMPORT" -X POST "$@"; }
check "POST bez CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST -H "Cookie: $C_ADMIN" -d "fingerprint=$FP" "$IMPORT")"
check "POST z nieprawidłowym odciskiem → przekierowanie" 302 "$(post -d "_token=$TOKEN&fingerprint=abc" "$IMPORT")"
has "Komunikat walidacji odcisku" "$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")" "Podgląd importu jest nieprawidłowy"

OJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
O_TOKEN=$(token "$(curl -s --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" "$SITE/app/projects")")
check "POST pracownika bez przypisania (ważny CSRF) → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" -X POST -d "_token=$O_TOKEN&fingerprint=$FP" "$IMPORT")"
check "POST reguły przez pracownika bez przypisania → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" -X POST -d "_token=$O_TOKEN&node=10:900&action=include" "$RULES")"
rm -f "$OJAR"

check "POST z odciskiem z podglądu → przekierowanie" 302 "$(post -d "_token=$TOKEN&fingerprint=$FP" "$IMPORT")"
AFTER=$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")
has "Komunikat o zatwierdzeniu" "$AFTER" "Import zatwierdzony: nowe 29"
has "Po imporcie: wszystko bez zmian" "$AFTER" "Bez zmian: 29"
has "Po imporcie: przycisk nieaktywny i informacja" "$AFTER" "Brak zmian — struktura podstrony"
check "Ponowny POST ze starym odciskiem → przekierowanie" 302 "$(post -d "_token=$TOKEN&fingerprint=$FP" "$IMPORT")"
has "Stary odcisk odrzucony" "$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")" "zmieniła się od wyświetlenia podglądu"

echo "== Wykluczenia i przywracanie (POST) =="
check "Reguła bez CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST -H "Cookie: $C_ADMIN" -d "node=10:900&action=include" "$RULES")"
check "Niepoprawne ID warstwy → przekierowanie" 302 "$(post -d "_token=$TOKEN&node=../1&action=include" "$RULES")"
has "Walidacja ID warstwy" "$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")" "Nieprawidłowy identyfikator warstwy"
check "Niedozwolona operacja → przekierowanie" 302 "$(post -d "_token=$TOKEN&node=10:900&action=delete" "$RULES")"
has "Walidacja operacji" "$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")" "Nieprawidłowa operacja"
check "Warstwa spoza frame'u → przekierowanie" 302 "$(post -d "_token=$TOKEN&node=99:99&action=exclude" "$RULES")"
has "Komunikat: brak warstwy we frame'ie" "$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")" "nie ma takiej warstwy"
check "Reguła dla podstrony B (bez frame'u) → przekierowanie" 302 "$(post -d "_token=$TOKEN&node=10:900&action=include" "$SITE/app/projects/$PB/pages/$PAGE_B/figma/rules")"
check "Przywrócenie stopki → przekierowanie" 302 "$(post -d "_token=$TOKEN&node=10:900&action=include" "$RULES")"
RESTORED=$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$IMPORT")
has "Komunikat o regule" "$RESTORED" "Warstwa zostanie uwzględniona w imporcie"
has "Lista przywróconych ręcznie" "$RESTORED" "Przywrócone ręcznie (1)"
has "Stopka w podglądzie jako nowe elementy (sekcja + 2 pola)" "$RESTORED" "Nowe: 3"
has "Wykluczonych o jedną mniej" "$RESTORED" "Wykluczone warstwy (7)"
rm -f "$JAR"

echo
echo "HTTP etapu 05: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
