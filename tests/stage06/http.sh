#!/usr/bin/env bash
# Etap 06 — testy HTTP konfiguratora na działającym LocalWP (http://fillmein.local).
# Wymaga manifestu z tests/stage03/setup.php i zasiewu tests/stage06/seed.php.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage06-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }
id() { json "$IDS" "$1"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b)
PAGE_A=$(json "$MANIFEST" pages.a); PAGE_B=$(json "$MANIFEST" pages.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
FIRST=$(id first); SECOND=$(id second); TEXT=$(id text); OTHER=$(id other); REPEATER=$(id repeater); NESTED=$(id nested)
WITH_CONTENT=$(id with_content); F_SECTION=$(id foreign_section); F_FIELD=$(id foreign_field)

BASE="$SITE/app/projects/$PA/pages/$PAGE_A"
BASE_B="$SITE/app/projects/$PB/pages/$PAGE_B"
STRUCTURE="$BASE/structure"

PASS=0; FAIL=0
code() { if [ "$1" = "-" ]; then curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$2"; else curl -s -o /dev/null -w '%{http_code}' --max-time 25 -H "Cookie: $1" "$2"; fi; }
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -q -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
token() { printf '%s' "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
OJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
trap 'rm -f "$JAR" "$OJAR"' EXIT

get() { curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$1"; }
HTML=$(get "$STRUCTURE")
TOKEN=$(token "$HTML")
post() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "Referer: $STRUCTURE" -X POST --data-urlencode "_token=$TOKEN" "$@"; }
postjson() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "Accept: application/json" -H "X-CSRF-TOKEN: $TOKEN" -X POST "$@"; }

echo "== Dostęp do widoków =="
GETS=("$STRUCTURE" "$BASE/sections/$FIRST/edit" "$BASE/sections/$FIRST/fields/create" "$BASE/fields/$TEXT/edit")
for url in "${GETS[@]}"; do
  path="${url#"$BASE"}"
  check "Gość $path → logowanie" 302 "$(code - "$url")"
  check "Pracownik bez przypisania $path → 404" 404 "$(code "$C_OUTSIDER" "$url")"
  check "Przypisany pracownik $path → 200" 200 "$(code "$C_MEMBER" "$url")"
  check "Administrator $path → 200" 200 "$(code "$C_ADMIN" "$url")"
done

echo "== Treść widoku =="
has "Nagłówek strony" "$HTML" "Sekcje i pola"
has "Sekcja z seeda" "$HTML" "Sekcja pierwsza"
has "Pole zagnieżdżone" "$HTML" "Tytuł karty"
has "Etykieta pochodzenia" "$HTML" "Ręcznie"
has "Przycisk klawiatury: wyżej" "$HTML" "Przenieś wyżej: Nagłówek"
has "Przycisk klawiatury: niżej" "$HTML" "Przenieś niżej: Nagłówek"
hasnt "Brak treści klienta w konfiguratorze" "$HTML" "Treść testowa"
has "Link z edycji podstrony" "$(curl -s --max-time 25 -H "Cookie: $C_ADMIN" "$BASE/edit")" "/structure"

echo "== IDOR: każda trasa z obcym ID =="
check "GET sekcja B pod podstroną A → 404" 404 "$(code "$C_ADMIN" "$BASE/sections/$F_SECTION/edit")"
check "GET pole B pod podstroną A → 404" 404 "$(code "$C_ADMIN" "$BASE/fields/$F_FIELD/edit")"
check "GET nowe pole w sekcji B pod A → 404" 404 "$(code "$C_ADMIN" "$BASE/sections/$F_SECTION/fields/create")"
check "GET nowe pole z obcym rodzicem → 404" 404 "$(code "$C_ADMIN" "$BASE/sections/$FIRST/fields/create?parent=$F_FIELD")"
check "GET podstrona A pod projektem B → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PB/pages/$PAGE_A/structure")"
check "POST edycja sekcji B → 404" 404 "$(post -d "name=Hack" "$BASE/sections/$F_SECTION")"
check "POST archiwum sekcji B → 404" 404 "$(post "$BASE/sections/$F_SECTION/archive")"
check "POST przywrócenie sekcji B → 404" 404 "$(post "$BASE/sections/$F_SECTION/restore")"
check "POST nowe pole w sekcji B → 404" 404 "$(post -d "label=Hack&type=text" "$BASE/sections/$F_SECTION/fields")"
check "POST kolejność pól sekcji B → 404" 404 "$(post -d "order[]=$F_FIELD" "$BASE/sections/$F_SECTION/fields/reorder")"
check "POST edycja pola B → 404" 404 "$(post -d "label=Hack&type=text" "$BASE/fields/$F_FIELD")"
check "POST archiwum pola B → 404" 404 "$(post "$BASE/fields/$F_FIELD/archive")"
check "POST przywrócenie pola B → 404" 404 "$(post "$BASE/fields/$F_FIELD/restore")"
check "Kolejność sekcji z ID sekcji B → 422" 422 "$(postjson -d "order[]=$F_SECTION&order[]=$FIRST" "$BASE/sections/reorder")"
check "Kolejność pól z ID pola B → 422" 422 "$(postjson -d "order[]=$F_FIELD" "$BASE/sections/$FIRST/fields/reorder")"
check "Kolejność pól z obcym rodzicem → 422" 422 "$(postjson -d "order[]=$TEXT&parent_id=$F_FIELD" "$BASE/sections/$FIRST/fields/reorder")"
check "Nowe pole z obcym rodzicem → 422" 422 "$(postjson -d "label=Hack&type=text&parent_id=$F_FIELD" "$BASE/sections/$FIRST/fields")"

echo "== Pracownik bez przypisania (ważny CSRF) =="
O_TOKEN=$(token "$(curl -s --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" "$SITE/app/projects")")
opost() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" -X POST -d "_token=$O_TOKEN" "$@"; }
check "Nowa sekcja → 404" 404 "$(opost -d "name=Hack" "$BASE/sections")"
check "Kolejność sekcji → 404" 404 "$(opost -d "order[]=$FIRST" "$BASE/sections/reorder")"
check "Edycja sekcji → 404" 404 "$(opost -d "name=Hack" "$BASE/sections/$FIRST")"
check "Archiwum sekcji → 404" 404 "$(opost "$BASE/sections/$FIRST/archive")"
check "Przywrócenie sekcji → 404" 404 "$(opost "$BASE/sections/$FIRST/restore")"
check "Nowe pole → 404" 404 "$(opost -d "label=Hack&type=text" "$BASE/sections/$FIRST/fields")"
check "Kolejność pól → 404" 404 "$(opost -d "order[]=$TEXT" "$BASE/sections/$FIRST/fields/reorder")"
check "Edycja pola → 404" 404 "$(opost -d "label=Hack&type=text" "$BASE/fields/$TEXT")"
check "Archiwum pola → 404" 404 "$(opost "$BASE/fields/$TEXT/archive")"
check "Przywrócenie pola → 404" 404 "$(opost "$BASE/fields/$TEXT/restore")"

echo "== CSRF: każda trasa zapisu =="
nocsrf() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 -H "Cookie: $C_ADMIN" -X POST -d "name=Hack&label=Hack&type=text&order[]=1" "$1"; }
for url in "$BASE/sections" "$BASE/sections/reorder" "$BASE/sections/$FIRST" "$BASE/sections/$FIRST/archive" "$BASE/sections/$FIRST/restore" \
  "$BASE/sections/$FIRST/fields" "$BASE/sections/$FIRST/fields/reorder" "$BASE/fields/$TEXT" "$BASE/fields/$TEXT/archive" "$BASE/fields/$TEXT/restore"; do
  check "Bez tokenu ${url#"$BASE"} → 419" 419 "$(nocsrf "$url")"
done

echo "== Walidacja: 422 zamiast 500 =="
FIELDS="$BASE/sections/$FIRST/fields"
check "Nieobsługiwany typ → 422" 422 "$(postjson -d "label=X&type=video" "$FIELDS")"
check "Brak etykiety → 422" 422 "$(postjson -d "type=text" "$FIELDS")"
check "Duplikat klucza → 422" 422 "$(postjson -d "label=X&type=text&name=naglowek" "$FIELDS")"
check "Klucz w złym formacie → 422" 422 "$(postjson -d "label=X&type=text&name=Zły-Klucz" "$FIELDS")"
check "Min większe niż max → 422" 422 "$(postjson -d "label=X&type=repeater&settings[min_items]=9&settings[max_items]=2" "$FIELDS")"
check "Limit ponad twardy limit → 422" 422 "$(postjson -d "label=X&type=repeater&settings[max_items]=5000" "$FIELDS")"
check "Rodzic niebędący kontenerem → 422" 422 "$(postjson -d "label=X&type=text&parent_id=$TEXT" "$FIELDS")"
check "Pusta kolejność → 422" 422 "$(postjson -d "x=1" "$BASE/sections/reorder")"
check "Sekcja bez nazwy → 422" 422 "$(postjson -d "x=1" "$BASE/sections")"
check "Zmiana typu z treścią bez potwierdzenia → 422" 422 "$(postjson -d "label=Z treścią&type=image&name=z_trescia" "$BASE/fields/$WITH_CONTENT")"

echo "== Operacje (formularze) =="
check "Nowa sekcja → przekierowanie" 302 "$(post --data-urlencode "name=Sekcja HTTP <b>" "$BASE/sections")"
AFTER=$(get "$STRUCTURE")
has "Komunikat o dodaniu" "$AFTER" "została dodana"
has "Nazwa sekcji escapowana" "$AFTER" "Sekcja HTTP &lt;b&gt;"
hasnt "Brak surowego HTML" "$AFTER" "Sekcja HTTP <b>"

check "Nowe pole tekstowe" 302 "$(post --data-urlencode "label=Pole HTTP" -d "type=text&settings[max_length]=80" "$FIELDS")"
check "Nowa grupa" 302 "$(post -d "label=Grupa HTTP&type=group" "$FIELDS")"
check "Pole podrzędne repeatera" 302 "$(post -d "label=Podrzedne HTTP&type=link&parent_id=$REPEATER" "$FIELDS")"
check "Edycja pola" 302 "$(post -d "label=Nagłówek po zmianie&type=text&name=naglowek&is_required=1" "$BASE/fields/$TEXT")"
check "Edycja sekcji" 302 "$(post -d "name=Sekcja po zmianie&is_hidden=1" "$BASE/sections/$SECOND")"

check "Zmiana typu z treścią bez potwierdzenia → powrót" 302 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "Referer: $BASE/fields/$WITH_CONTENT/edit" -X POST --data-urlencode "_token=$TOKEN" -d "label=Z tresc&type=image&name=z_trescia" "$BASE/fields/$WITH_CONTENT")"
CONFIRM=$(get "$BASE/fields/$WITH_CONTENT/edit")
has "Formularz prosi o potwierdzenie" "$CONFIRM" "Zmiana typu usunie treść"
has "Pole potwierdzenia" "$CONFIRM" 'name="confirm_type_change"'
check "Zmiana typu z potwierdzeniem" 302 "$(post -d "label=Z tresc&type=image&name=z_trescia&confirm_type_change=1" "$BASE/fields/$WITH_CONTENT")"

check "Kolejność sekcji (formularz)" 302 "$(post -d "order[]=$SECOND&order[]=$FIRST" "$BASE/sections/reorder")"
check "Kolejność pól (JSON)" 200 "$(postjson -d "order[]=$OTHER&order[]=$TEXT" "$FIELDS/reorder")"
check "Kolejność dzieci repeatera (JSON)" 200 "$(postjson -d "order[]=$NESTED&parent_id=$REPEATER" "$FIELDS/reorder")"

check "Archiwum pola" 302 "$(post "$BASE/fields/$OTHER/archive")"
has "Lista pól w archiwum" "$(get "$STRUCTURE")" "Pola w archiwum (1)"
check "Przywrócenie pola" 302 "$(post "$BASE/fields/$OTHER/restore")"
check "Archiwum sekcji" 302 "$(post "$BASE/sections/$SECOND/archive")"
has "Lista sekcji w archiwum" "$(get "$STRUCTURE")" "Sekcje w archiwum (1)"
check "Przywrócenie sekcji" 302 "$(post "$BASE/sections/$SECOND/restore")"
check "Konfigurator projektu B po operacjach na A" 200 "$(code "$C_ADMIN" "$BASE_B/structure")"

echo "== Bez błędów 500 =="
check "Design system z przykładem sortowania" 200 "$(code "$C_ADMIN" "$SITE/app/design-system")"

echo
echo "HTTP etapu 06: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
