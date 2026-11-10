#!/usr/bin/env bash
# Etap 07 — testy HTTP linków klienta i edytora na działającym LocalWP.
# Wymaga manifestu z tests/stage03/setup.php i zasiewu tests/stage07/seed.php.
# Tokeny nie trafiają na wyjście: etykiety testów ich nie zawierają, a nagłówki są zapisywane do pliku tymczasowego.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage07-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }
f() { json "$IDS" "fields.$1"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MANAGER=$(json "$MANIFEST" cookies.manager); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
TOKEN_A=$(json "$IDS" tokens.a); TOKEN_B=$(json "$IDS" tokens.b); REVOKED=$(json "$IDS" tokens.revoked); EXPIRED=$(json "$IDS" tokens.expired)
ROW_A=$(json "$IDS" rows.a); ROW_F=$(json "$IDS" rows.foreign)
ACCESS_A=$(json "$IDS" access_a)
EDITOR="$SITE/p/$TOKEN_A"; EDITOR_B="$SITE/p/$TOKEN_B"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -q -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono niedozwolony fragment"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
hasntf() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono sekret"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); SJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); HDR=$(mktemp "${TMPDIR:-/tmp}/fmi-hdr.XXXXXX"); B=$(mktemp "${TMPDIR:-/tmp}/fmi-hdr.XXXXXX")
trap 'rm -f "$JAR" "$SJAR" "$HDR" "$B"' EXIT

meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
form_token() { printf '%s' "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$@"; }

echo "== Złe tokeny: identyczna odpowiedź =="
norm() { sed -E 's/name="csrf-token" content="[^"]*"/CSRF/'; }
capture() { curl -s -D "$HDR" -o "$B" --max-time 25 "$SITE/p/$1"; head -1 "$HDR" | tr -d '\r'; grep -iE '^(x-robots-tag|referrer-policy|content-type|cache-control):' "$HDR" | tr -d '\r' | tr 'A-Z' 'a-z' | sort; norm < "$B"; }
MISSING=$(capture "$(printf 'x%.0s' {1..43})")
has "Nieistniejący token → 404" "$MISSING" "404"
has "Strona nieaktywnego linku" "$MISSING" "Ten link jest nieaktywny"
for name in REVOKED EXPIRED; do
  OTHER=$(capture "${!name}")
  if [ "$OTHER" = "$MISSING" ]; then echo "  PASS  $name: odpowiedź identyczna z nieistniejącym tokenem"; PASS=$((PASS+1)); else echo "  FAIL  $name: odpowiedź różni się"; FAIL=$((FAIL+1)); fi
done
OTHER=$(capture "krotki")
if [ "$OTHER" = "$MISSING" ]; then echo "  PASS  Zły format: odpowiedź identyczna"; PASS=$((PASS+1)); else echo "  FAIL  Zły format: odpowiedź różni się"; FAIL=$((FAIL+1)); fi
# Bez CSRF nieważny i ważny token dostają to samo 419 (CSRF działa przed `fmi.client`), więc status nic nie zdradza.
check "POST bez CSRF z nieważnym tokenem → 419" 419 "$(code -X POST -d 'base_version=0' "$SITE/p/$REVOKED/fields/$(f text)")"

echo "== Nagłówki edytora =="
HTML=$(curl -s -D "$HDR" -c "$JAR" -b "$JAR" --max-time 25 "$EDITOR")
HEAD=$(tr -d '\r' < "$HDR")
has "200" "$HEAD" " 200"
has "Referrer-Policy: no-referrer" "$HEAD" "[Rr]eferrer-[Pp]olicy: no-referrer"
has "X-Robots-Tag: noindex" "$HEAD" "[Xx]-[Rr]obots-[Tt]ag: noindex"
hasntf "Nagłówki bez tokenu" "$HEAD" "$TOKEN_A"
hasntf "HTML bez tokenu" "$HTML" "$TOKEN_A"
has "Meta referrer" "$HTML" 'name="referrer" content="no-referrer"'
CSRF=$(meta "$HTML")

echo "== Treść edytora =="
for text in "Sekcja główna" "Nagłówek" "Maksymalnie kilka słów" "Opis" "Treść sformatowana" "Tekst linku" "Telefon" "Karty" "Tytuł karty" "Element 1" "Punkty" "Tekst alternatywny\|type=\"file\"" "Podstrona podrzędna"; do
  has "Widoczne: $text" "$HTML" "$text"
done
for text in "Pole ukryte" "Pole archiwalne" "Dziecko archiwum" "Sekcja archiwalna" "W sekcji archiwalnej" "Sekcja ukryta" "W sekcji ukrytej" "Podstrona archiwalna" "W podstronie archiwalnej" "Pole B"; do
  hasnt "Niewidoczne: $text" "$HTML" "$text"
done
hasnt "Bez kontrolek struktury" "$HTML" "fields/create\|sections/reorder"
CHILD_HTML=$(curl -s -c "$JAR" -b "$JAR" --max-time 25 "$EDITOR?strona=$(json "$IDS" pages.child)")
has "Nawigacja: podstrona podrzędna" "$CHILD_HTML" "Pole podstrony"
has "Nawigacja: aria-current" "$CHILD_HTML" 'aria-current="page"'
check "Podstrona archiwalna → 404" 404 "$(code -b "$JAR" "$EDITOR?strona=$(json "$IDS" pages.archived)")"
check "Podstrona innego projektu → 404" 404 "$(code -b "$JAR" "$EDITOR?strona=$(json "$MANIFEST" pages.b)")"

echo "== CSRF =="
check "Zapis bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d 'base_version=0&value=x' "$EDITOR/fields/$(f text)")"
check "Dodanie wiersza bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d 'base_version=0' "$EDITOR/fields/$(f repeater)/rows")"
check "Sortowanie bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d "base_version=0&order[]=$ROW_A" "$EDITOR/fields/$(f repeater)/rows/reorder")"
check "Usunięcie wiersza bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d 'base_version=0' "$EDITOR/fields/$(f repeater)/rows/$ROW_A/delete")"

echo "== Złe tokeny na endpointach zapisu (z ważnym CSRF) =="
bad() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CSRF" -X POST -d 'base_version=0&value=x' "$SITE/p/$1/fields/$2"; }
check "Unieważniony token → 404" 404 "$(bad "$REVOKED" "$(f text)")"
check "Wygasły token → 404" 404 "$(bad "$EXPIRED" "$(f text)")"
check "Nieistniejący token → 404" 404 "$(bad "$(printf 'y%.0s' {1..43})" "$(f text)")"
check "Niewidoczne pole z nieważnym tokenem → 404, nie 403" 404 "$(bad "$EXPIRED" "$(f archived)")"
check "Token B na polu A → 404" 404 "$(bad "$TOKEN_B" "$(f text)")"

api() { local path="$1"; shift; curl -s -D "$HDR" -o "$B" -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CSRF" -X POST "$@" "$EDITOR/$path"; }
body() { cat "$B"; }

echo "== Zapisy =="
check "Tekst" 200 "$(api "fields/$(f text)" -d 'base_version=0' --data-urlencode 'value=Tytuł klienta')"
has "Odpowiedź z wersją" "$(body)" '"version":1'
hasnt "Zapis bez przekierowania" "$(tr -d '\r' < "$HDR")" "^[Ll]ocation:"
check "Textarea" 200 "$(api "fields/$(f textarea)" -d 'base_version=0&value=Opis')"
check "WYSIWYG" 200 "$(api "fields/$(f wysiwyg)" -d 'base_version=0' --data-urlencode 'value=<p><strong>Mocno</strong></p><script>x</script>')"
check "Link" 200 "$(api "fields/$(f link)" -d 'base_version=0' -d 'label=Więcej' --data-urlencode 'url=https://example.com/x')"
check "Pole w grupie" 200 "$(api "fields/$(f in_group)" -d 'base_version=0&value=123')"
check "Pole w wierszu" 200 "$(api "fields/$(f row_field)" -d "base_version=0&value=Karta&row=$ROW_A")"
check "Wysłany dwa razy ten sam zapis → 200" 200 "$(api "fields/$(f textarea)" -d 'base_version=1&value=Opis')"
check "Nieaktualna wersja → 409" 409 "$(api "fields/$(f text)" -d 'base_version=0&value=Stara')"
check "Ponad limit znaków → 422" 422 "$(api "fields/$(f text)" -d 'base_version=1' --data-urlencode "value=$(printf 'x%.0s' {1..41})")"
check "Adres javascript: → 422" 422 "$(api "fields/$(f link)" -d 'base_version=1' --data-urlencode 'url=javascript:alert(1)')"
check "Brak wersji → 422" 422 "$(api "fields/$(f text)" -d 'value=x')"
check "Zapis do zdjęcia → 422" 422 "$(api "fields/$(f image)" -d 'base_version=0&value=x')"
check "Zapis do galerii → 422" 422 "$(api "fields/$(f gallery)" -d 'base_version=0&value=x')"
check "Zapis do grupy → 422" 422 "$(api "fields/$(f group)" -d 'base_version=0&value=x')"
check "Zapis do repeatera → 422" 422 "$(api "fields/$(f repeater)" -d 'base_version=0&value=x')"
check "Pole w liście bez wiersza → 422" 422 "$(api "fields/$(f row_field)" -d 'base_version=0&value=x')"

echo "== Niewidoczne elementy → 403 =="
for name in hidden archived child_of_archived in_archived_section in_hidden_section in_archived_page; do
  check "$name" 403 "$(api "fields/$(f "$name")" -d 'base_version=0&value=x')"
done

echo "== IDOR między projektami =="
check "Pole B tokenem A → 404" 404 "$(api "fields/$(f foreign)" -d 'base_version=0&value=x')"
check "Pole repeatera B tokenem A → 404" 404 "$(api "fields/$(f foreign_row_field)" -d "base_version=0&value=x&row=$ROW_F")"
check "Wiersz B do repeatera B tokenem A → 404" 404 "$(api "fields/$(f foreign_repeater)/rows" -d 'base_version=1')"
check "Usunięcie wiersza B tokenem A → 404" 404 "$(api "fields/$(f foreign_repeater)/rows/$ROW_F/delete" -d 'base_version=1')"
check "Sortowanie repeatera B tokenem A → 404" 404 "$(api "fields/$(f foreign_repeater)/rows/reorder" -d "base_version=1&order[]=$ROW_F")"
check "Wiersz B w polu A → 404" 404 "$(api "fields/$(f row_field)" -d "base_version=0&value=x&row=$ROW_F")"
check "Usunięcie wiersza B przez repeater A → 404" 404 "$(api "fields/$(f repeater)/rows/$ROW_F/delete" -d 'base_version=1')"
check "Sortowanie repeatera A wierszem B → 422" 422 "$(api "fields/$(f repeater)/rows/reorder" -d "base_version=1&order[]=$ROW_F")"
HTML_B=$(curl -s --max-time 25 "$EDITOR_B")
hasnt "Edytor B bez danych A" "$HTML_B" "Sekcja główna"
has "Edytor B z własnymi danymi" "$HTML_B" "Sekcja B"

echo "== Wiersze repeatera =="
check "Dodanie wiersza" 200 "$(api "fields/$(f repeater)/rows" -d 'base_version=1')"
has "Odpowiedź z HTML wiersza" "$(body)" '"html"'
hasntf "HTML wiersza bez tokenu" "$(body)" "$TOKEN_A"
NEW=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); preg_match("/data-sortable-item=\"([^\"]+)\"/",$j["html"],$m); echo $m[1];' "$B")
check "Sortowanie" 200 "$(api "fields/$(f repeater)/rows/reorder" -d "base_version=2&order[]=$NEW&order[]=$ROW_A")"
check "Sortowanie niepełne → 422" 422 "$(api "fields/$(f repeater)/rows/reorder" -d "base_version=3&order[]=$NEW")"
check "Zagnieżdżony wiersz" 200 "$(api "fields/$(f nested)/rows" -d "base_version=0&row=$NEW")"
INNER=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); preg_match("/data-sortable-item=\"([^\"]+)\"/",$j["html"],$m); echo $m[1];' "$B")
check "Pole w zagnieżdżonym wierszu" 200 "$(api "fields/$(f nested_field)" -d "base_version=0&value=Punkt&row=$INNER")"
check "Trzeci wiersz" 200 "$(api "fields/$(f repeater)/rows" -d 'base_version=3')"
THIRD=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); preg_match("/data-sortable-item=\"([^\"]+)\"/",$j["html"],$m); echo $m[1];' "$B")
check "Czwarty ponad limit → 422" 422 "$(api "fields/$(f repeater)/rows" -d 'base_version=4')"
check "Usunięcie wiersza" 200 "$(api "fields/$(f repeater)/rows/$THIRD/delete" -d 'base_version=4')"
check "Nieaktualna wersja listy → 409" 409 "$(api "fields/$(f repeater)/rows" -d 'base_version=1')"

echo "== Panel linków =="
SHOW="$SITE/app/projects/$PA"
PANEL=$(curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$SHOW")
has "Karta linków" "$PANEL" "Linki dla klienta"
has "Status aktywny" "$PANEL" "Aktywny"
has "Status unieważniony" "$PANEL" "Unieważniony"
has "Status wygasły" "$PANEL" "Wygasł"
has "Panel: adres aktywnego linku widoczny dla zespołu" "$PANEL" "$TOKEN_A"
STOKEN=$(form_token "$PANEL")
apost() { curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 -X POST --data-urlencode "_token=$STOKEN" "$@"; }
acode() { curl -s -o /dev/null -w '%{http_code}' -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 -X POST --data-urlencode "_token=$STOKEN" "$@"; }
check "Generowanie bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -d 'expires_in=30' "$SHOW/client-access")"
check "Regeneracja bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -d 'expires_in=30' "$SHOW/client-access/regenerate")"
check "Unieważnienie bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" "$SHOW/client-access/$ACCESS_A/revoke")"

apost -d 'expires_in=7' -d 'label=Link testowy' "$SHOW/client-access" >/dev/null
ISSUED=$(curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$SHOW")
has "Wygenerowano link (powrót do projektu)" "$ISSUED" "Link dla klienta został wygenerowany"
has "Link bez terminu (dawne expires_in ignorowane)" "$ISSUED" "ważny bez terminu"
NEW_TOKEN=$(printf '%s' "$ISSUED" | grep -o 'value="[^"]*/p/[^"]*"' | head -1 | sed 's/.*\/p\///;s/"$//')
check "Adres ma token" 1 "$(printf '%s' "$NEW_TOKEN" | grep -cE '^[A-Za-z0-9_-]{43}$')"
printf '%s' "$NEW_TOKEN" > "${IDS}.new"
check "Nowy link działa" 200 "$(code "$SITE/p/$NEW_TOKEN")"
AFTER=$(curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$SHOW")
has "Po odświeżeniu adres dalej widoczny w panelu" "$AFTER" "$NEW_TOKEN"
has "Opis na liście" "$AFTER" "Link testowy"

OTOKEN_JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
O_TOKEN=$(form_token "$(curl -s -c "$OTOKEN_JAR" -b "$OTOKEN_JAR" -H "Cookie: $C_OUTSIDER" --max-time 25 "$SITE/app/projects")")
opost() { curl -s -o /dev/null -w '%{http_code}' -c "$OTOKEN_JAR" -b "$OTOKEN_JAR" -H "Cookie: $C_OUTSIDER" --max-time 25 -X POST -d "_token=$O_TOKEN" "$@"; }
check "Pracownik bez przypisania: generowanie → 404" 404 "$(opost -d 'expires_in=30' "$SHOW/client-access")"
check "Pracownik bez przypisania: regeneracja → 404" 404 "$(opost -d 'expires_in=30' "$SHOW/client-access/regenerate")"
check "Pracownik bez przypisania: unieważnienie → 404" 404 "$(opost "$SHOW/client-access/$ACCESS_A/revoke")"
rm -f "$OTOKEN_JAR"
check "Link A pod projektem B → 404" 404 "$(acode "$SITE/app/projects/$PB/client-access/$ACCESS_A/revoke")"

check "Unieważnienie" 302 "$(acode "$SHOW/client-access/$ACCESS_A/revoke")"
check "Unieważniony link → 404" 404 "$(code "$EDITOR")"
check "Zapis po unieważnieniu → 404" 404 "$(api "fields/$(f textarea)" -d 'base_version=1&value=Po')"
apost -d 'expires_in=never' "$SHOW/client-access/regenerate" >/dev/null
REGEN=$(curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$SHOW")
has "Regeneracja: nowy adres" "$REGEN" "Wygenerowano nowy link dla klienta"
check "Regeneracja unieważnia poprzedni" 404 "$(code "$SITE/p/$NEW_TOKEN")"

echo
echo "HTTP etapu 07: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
