#!/usr/bin/env bash
# Etap 11 — testy HTTP mapowania i eksportu. Token klienta nie trafia na wyjście.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage11-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b); PAGE=$(json "$MANIFEST" pages.a); PAGE_B=$(json "$MANIFEST" pages.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider); C_MANAGER=$(json "$MANIFEST" cookies.manager)
TOKEN=$(json "$IDS" token); TITLE=$(json "$IDS" fields.title); LEAD=$(json "$IDS" fields.lead); UNMAPPED=$(json "$IDS" fields.unmapped)
MAPPING="$SITE/app/projects/$PA/mapping"
EXPORTS="$SITE/app/projects/$PA/exports"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -q -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
hasntf() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono wartość zabronioną"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
trap 'rm -f "$JAR" /tmp/fmi-stage11-export.json /tmp/fmi-stage11-export.zip' EXIT

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$@"; }
admin() { curl -s --max-time 40 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$@"; }
meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
post() { curl -s -o /dev/null -w '%{http_code}' --max-time 60 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" "$@"; }
postas() { local jar page token; jar=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); page=$(curl -s --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" "$SITE/app"); token=$(meta "$page"); curl -s -o /dev/null -w '%{http_code}' --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" -H "X-CSRF-TOKEN: $token" -X POST "$2"; rm -f "$jar"; }
FORM="page[post_type]=page&complete=1"

echo "== Dostęp do widoków =="
check "Mapowanie: administrator" 200 "$(code -H "Cookie: $C_ADMIN" "$MAPPING")"
check "Mapowanie: opiekun" 200 "$(code -H "Cookie: $C_MANAGER" "$MAPPING")"
check "Mapowanie: przypisany bez capability" 403 "$(code -H "Cookie: $C_MEMBER" "$MAPPING")"
check "Mapowanie: spoza projektu" 404 "$(code -H "Cookie: $C_OUTSIDER" "$MAPPING")"
check "Mapowanie: gość" 302 "$(code "$MAPPING")"
check "Eksporty: administrator" 200 "$(code -H "Cookie: $C_ADMIN" "$EXPORTS")"
check "Eksporty: przypisany bez capability" 403 "$(code -H "Cookie: $C_MEMBER" "$EXPORTS")"
check "Eksporty: spoza projektu" 404 "$(code -H "Cookie: $C_OUTSIDER" "$EXPORTS")"
check "Eksporty: gość" 302 "$(code "$EXPORTS")"

echo "== Mapowanie =="
HTML=$(admin "$MAPPING?strona=$PAGE")
CSRF=$(meta "$HTML")
has "Formularz: typ wpisu" "$HTML" 'name="page\[post_type\]"'
has "Formularz: nazwa pola" "$HTML" "name=\"fields\[$TITLE\]\[name\]\""
has "Formularz: typ pola" "$HTML" "name=\"fields\[$TITLE\]\[type\]\""
has "Ścieżka ACF widoczna" "$HTML" '\.r_lista'
hasntf "Bez tokenu w widoku" "$HTML" "$TOKEN"
check "Bez CSRF" 419 "$(code -H "Cookie: $C_ADMIN" -X POST -d "$FORM" "$MAPPING/$PAGE")"
check "Przypisany bez capability" 403 "$(postas "$C_MEMBER" "$MAPPING/$PAGE")"
check "Spoza projektu" 404 "$(postas "$C_OUTSIDER" "$MAPPING/$PAGE")"
check "Podstrona innego projektu" 404 "$(post -X POST -d "$FORM" "$MAPPING/$PAGE_B")"
check "Pole innej podstrony: bez zapisu" 302 "$(post -X POST -d "$FORM&fields[$UNMAPPED][name]=obce" "$MAPPING/$PAGE")"
AFTER=$(admin "$MAPPING?strona=$PAGE")
has "Komunikat: obce pole" "$AFTER" 'Formularz zawiera pola spoza tej podstrony'
check "Typ niezgodny: bez zapisu" 302 "$(post -X POST -d "$FORM&fields[$TITLE][type]=gallery" "$MAPPING/$PAGE")"
AFTER=$(admin "$MAPPING?strona=$PAGE")
has "Komunikat: typ niezgodny" "$AFTER" 'nie pasuje do pola'
check "Niepoprawna nazwa ACF" 302 "$(post -X POST -d "$FORM&fields[$TITLE][name]=Zla%20Nazwa" "$MAPPING/$PAGE")"
AFTER=$(admin "$MAPPING?strona=$PAGE")
has "Komunikat: nazwa" "$AFTER" 'małe litery, cyfry i podkreślenia'
check "Szablon z .. odrzucony" 302 "$(post -X POST -d "$FORM&page[template]=../../wp-config.php" "$MAPPING/$PAGE")"
AFTER=$(admin "$MAPPING?strona=$PAGE")
has "Komunikat: szablon" "$AFTER" 'ścieżka względna bez'
check "Formularz obcięty odrzucony" 302 "$(post -X POST -d "page[post_type]=page" "$MAPPING/$PAGE")"
AFTER=$(admin "$MAPPING?strona=$PAGE")
has "Komunikat: niekompletny" "$AFTER" 'Formularz jest niekompletny'
check "Poprawny zapis" 302 "$(post -X POST -d "$FORM&page[template]=template-custom.blade.php&fields[$TITLE][name]=header&fields[$TITLE][type]=text&fields[$LEAD][type]=wysiwyg" "$MAPPING/$PAGE")"
AFTER=$(admin "$MAPPING?strona=$PAGE")
has "Nadpisanie zapisane" "$AFTER" 'value="header"'
has "Komunikat zapisu" "$AFTER" 'Zapisano mapowanie'

echo "== Generowanie =="
check "Bez CSRF" 419 "$(code -H "Cookie: $C_ADMIN" -X POST "$EXPORTS")"
check "Przypisany bez capability" 403 "$(postas "$C_MEMBER" "$EXPORTS")"
check "Spoza projektu" 404 "$(postas "$C_OUTSIDER" "$EXPORTS")"
check "Generowanie" 302 "$(post -X POST "$EXPORTS")"
HTML=$(admin "$EXPORTS")
has "Komunikat" "$HTML" 'Eksport gotowy'
has "Historia: status" "$HTML" 'Gotowy'
has "Historia: wersja" "$HTML" 'wersja 1.1'
has "Raport: niezmapowane" "$HTML" 'Niezmapowane: 1'
has "Raport: miejsce braku" "$HTML" 'Główna sekcja'
hasntf "Bez tokenu w widoku" "$HTML" "$TOKEN"
hasnt "Bez ścieżki serwera" "$HTML" '/Users/'

UUID=$(printf '%s' "$HTML" | grep -o "/exports/[0-9a-f-]\{36\}/json" | head -1 | sed 's~/exports/~~;s~/json~~')
JSON_URL="$EXPORTS/$UUID/json"
ZIP_URL="$EXPORTS/$UUID/zip"

echo "== Pobieranie i IDOR =="
check "JSON: administrator" 200 "$(curl -s -o /tmp/fmi-stage11-export.json -w '%{http_code}' --max-time 40 -H "Cookie: $C_ADMIN" "$JSON_URL")"
check "ZIP: administrator" 200 "$(curl -s -o /tmp/fmi-stage11-export.zip -w '%{http_code}' --max-time 40 -H "Cookie: $C_ADMIN" "$ZIP_URL")"
check "JSON: spoza projektu" 404 "$(code -H "Cookie: $C_OUTSIDER" "$JSON_URL")"
check "ZIP: spoza projektu" 404 "$(code -H "Cookie: $C_OUTSIDER" "$ZIP_URL")"
check "JSON: przypisany bez capability" 403 "$(code -H "Cookie: $C_MEMBER" "$JSON_URL")"
check "JSON: gość" 302 "$(code "$JSON_URL")"
check "Eksport przez obcy projekt" 404 "$(code -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PB/exports/$UUID/json")"
check "Nieznany eksport" 404 "$(code -H "Cookie: $C_ADMIN" "$EXPORTS/00000000-0000-4000-8000-000000000000/json")"
check "Nieznany format" 404 "$(code -H "Cookie: $C_ADMIN" "$EXPORTS/$UUID/txt")"
HEADERS=$(curl -s -D - -o /dev/null -H "Cookie: $C_ADMIN" "$JSON_URL")
has "Załącznik" "$HEADERS" 'attachment; filename='
has "Bez cache" "$HEADERS" 'no-store'

echo "== Zawartość pobranego pliku (C5) =="
BODY=$(cat /tmp/fmi-stage11-export.json)
hasntf "Bez tokenu klienta" "$BODY" "$TOKEN"
hasnt "Bez e-maili" "$BODY" '[A-Za-z0-9._%+-]\+@[A-Za-z0-9.-]\+'
hasnt "Bez ścieżki /Users/" "$BODY" '/Users/'
hasnt "Bez haseł" "$BODY" 'password\|user_pass\|hasło'
has "Wersja" "$BODY" '"schema_version": "1.1"'
has "Nadpisanie w pliku" "$BODY" '"name": "header"'
ZIP=$(php -r '$z=new ZipArchive; $z->open($argv[1]); $n=[]; for($i=0;$i<$z->numFiles;$i++){$name=$z->getNameIndex($i); $n[]=$name; if(str_starts_with($name,"media/")){$sha=substr(basename($name),0,64); if(hash("sha256",$z->getFromIndex($i))!==$sha){echo "SHA_MISMATCH ";}}} echo count($n)," ",in_array("export.json",$n)?"json":"nojson"," ",$z->getFromName("export.json")===file_get_contents($argv[2])?"same":"diff";' /tmp/fmi-stage11-export.zip /tmp/fmi-stage11-export.json)
check "ZIP: content.json, export.json i pliki z poprawnymi sumami" "6 json same" "$ZIP"

echo
echo "HTTP etapu 11: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
