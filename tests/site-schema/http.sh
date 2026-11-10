#!/usr/bin/env bash
# Schemat strony — HTTP: wgrywanie (ustawienia i zakładka Mapowanie ACF), ekran przeglądu, ręczna zmiana,
# przywracanie automatu, „Dopasuj automatycznie”, eksport z nazwami ACF, uprawnienia (403 / 404 / 419).

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-site-schema-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a)
PB_PAGE=$(json "$MANIFEST" pages.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
HOME_ID=$(json "$IDS" pages.home)
EXTRA=$(json "$IDS" sections.extra)
HERO_TEXT=$(json "$IDS" fields.heroText)
BASE="$SITE/app/projects/$PA"
MAPPING="$BASE/mapping"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -qF -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }

WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmi-site.XXXXXX"); chmod 700 "$WORK"
JAR="$WORK/jar"
trap 'rm -rf "$WORK"' EXIT

php -r 'echo json_encode(require $argv[1]);' "$THEME/tests/site-schema/fixture.php" > "$WORK/schema.json"
printf '{"generator":{"format":"fmi-export"}}' > "$WORK/export.json"
printf 'abc' > "$WORK/schema.txt"

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$@"; }
admin() { curl -s --max-time 60 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$@"; }
meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
post() { curl -s -o /dev/null -w '%{http_code}' --max-time 120 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" -X POST "$@"; }
postas() { local jar page token; jar=$(mktemp "$WORK/jar.XXXXXX"); page=$(curl -s --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" "$SITE/app"); token=$(meta "$page"); curl -s -o /dev/null -w '%{http_code}' --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" -H "X-CSRF-TOKEN: $token" -X POST "$2"; }
fresh() { CSRF=$(meta "$(admin "$MAPPING")"); }

echo "== Bez schematu =="
HTML=$(admin "$MAPPING")
has "Mapowanie ACF: karta wgrywania" "$HTML" 'data-site-schema'
has "Dotychczasowy formularz mapowania" "$HTML" 'Zapisz mapowanie'
has "Ustawienia projektu: karta wgrywania" "$(admin "$BASE/edit")" 'Wgraj schemat strony (WordPress)'

echo "== Wgrywanie: błędy =="
fresh
check "Inny format → przekierowanie" 302 "$(post -F "schema=@$WORK/export.json;type=application/json" "$BASE/site-schema")"
has "Komunikat: to nie schemat" "$(admin "$MAPPING")" 'To nie jest schemat strony z wtyczki Fill Me In Sync'
fresh
check "Plik .txt → przekierowanie" 302 "$(post -F "schema=@$WORK/schema.txt" "$BASE/site-schema")"
has "Komunikat: plik .json" "$(admin "$MAPPING")" 'Schemat strony to plik .json'

echo "== Uprawnienia =="
check "Bez CSRF" 419 "$(code -H "Cookie: $C_ADMIN" -X POST "$BASE/site-schema")"
check "Wgrywanie: przypisany bez capability" 403 "$(postas "$C_MEMBER" "$BASE/site-schema")"
check "Wgrywanie: spoza projektu" 404 "$(postas "$C_OUTSIDER" "$BASE/site-schema")"
check "Automat: przypisany bez capability" 403 "$(postas "$C_MEMBER" "$BASE/site-mapping/auto")"
check "Zapis: spoza projektu" 404 "$(postas "$C_OUTSIDER" "$BASE/site-mapping/$HOME_ID")"
check "Przywracanie: przypisany bez capability" 403 "$(postas "$C_MEMBER" "$BASE/site-mapping/$HOME_ID/restore")"
fresh
check "Podstrona innego projektu" 404 "$(post --data 'complete=1' "$BASE/site-mapping/$PB_PAGE")"
check "Automat bez schematu → przekierowanie" 302 "$(post "$BASE/site-mapping/auto")"
has "Komunikat: najpierw schemat" "$(admin "$MAPPING")" 'Najpierw wgraj schemat strony.'

echo "== Wgranie i przegląd =="
fresh
check "Wgranie schematu" 302 "$(post -F "schema=@$WORK/schema.json;type=application/json" "$BASE/site-schema")"
HTML=$(admin "$MAPPING?strona=$HOME_ID")
has "Komunikat po wgraniu" "$HTML" 'Wgrano schemat strony i dopasowano projekt automatycznie.'
has "Adres strony ze schematu" "$HTML" 'https://greenq.example/'
has "Ekran przeglądu" "$HTML" 'data-site-form'
has "Lista podstron ze statusami" "$HTML" 'data-site-pages'
has "Bloki tej strony najpierw" "$HTML" 'optgroup label="Na tej stronie"'
has "Pozostałe bloki motywu" "$HTML" 'optgroup label="Pozostałe bloki motywu"'
has "Pełna ścieżka pola" "$HTML" 'Karty › Tytuł (r_cards › title)'
has "Odznaka pewności" "$HTML" 'data-site-status="green"'
has "Opcja nie importuj" "$HTML" '— nie importuj —'

echo "== Ręczna zmiana i przywracanie =="
CSRF=$(meta "$HTML")
check "Bez znacznika complete" 302 "$(post --data "fields[$HERO_TEXT]=" "$BASE/site-mapping/$HOME_ID")"
has "Komunikat: formularz niekompletny" "$(admin "$MAPPING?strona=$HOME_ID")" 'Formularz jest niekompletny'
fresh
check "Zapis ręczny" 302 "$(post --data "complete=1&fields[$HERO_TEXT]=&original_fields[$HERO_TEXT]=field_hero_g_top_text&sections[$EXTRA]=acf/text|&original_sections[$EXTRA]=x" "$BASE/site-mapping/$HOME_ID")"
HTML=$(admin "$MAPPING?strona=$HOME_ID")
has "Komunikat zapisu" "$HTML" 'Zapisano ręczne dopasowanie.'
has "Odznaka: ręcznie" "$HTML" 'data-site-status="manual"'
has "Odznaka: nie importuj" "$HTML" 'data-site-status="skipped"'
has "Przycisk Przywróć automat" "$HTML" "value=\"field:$HERO_TEXT\""
CSRF=$(meta "$HTML")
check "Dopasuj automatycznie" 302 "$(post --data "strona=$HOME_ID" "$BASE/site-mapping/auto")"
has "Ręczne po automacie" "$(admin "$MAPPING?strona=$HOME_ID")" 'data-site-status="skipped"'
fresh
check "Przywróć automat (pole)" 302 "$(post --data "restore=field:$HERO_TEXT" "$BASE/site-mapping/$HOME_ID/restore")"
HTML=$(admin "$MAPPING?strona=$HOME_ID")
has "Komunikat przywrócenia" "$HTML" 'Przywrócono dopasowanie automatyczne.'
CSRF=$(meta "$HTML")
check "Zły cel przywracania" 302 "$(post --data "restore=cokolwiek" "$BASE/site-mapping/$HOME_ID/restore")"
fresh
check "Przywróć automat (sekcja)" 302 "$(post --data "restore=section:$EXTRA" "$BASE/site-mapping/$HOME_ID/restore")"

echo "== Eksport =="
CSRF=$(meta "$(admin "$BASE/exports")")
check "Eksport ZIP" 302 "$(post --data 'scope=project' "$BASE/exports")"
UUID=$(admin "$BASE/exports" | grep -o "/exports/[0-9a-f-]\{36\}/json" | head -1 | sed 's~/exports/~~;s~/json~~')
check "Pobranie ZIP" 200 "$(curl -s -o "$WORK/export.zip" -w '%{http_code}' --max-time 40 -H "Cookie: $C_ADMIN" "$BASE/exports/$UUID/zip")"
check "export.json w ZIP: schemat i nazwy ACF" "https://greenq.example/ acf/hero g_top.header" "$(php -r '$z=new ZipArchive; $z->open($argv[1]); $d=json_decode($z->getFromName("export.json"),true); $s=array_column($d["sections"],null,"name"); $f=array_column($d["fields"],null,"label"); echo $d["schema"]["site_url"]," ",$s["Frame 347"]["mapping"]["acf_block"]," ",$f["Zachowaj ciepło i oszczędzaj energię"]["acf"]["path"];' "$WORK/export.zip")"
check "ZIP: content.json i media/ bez zmian" "fmi-content" "$(php -r '$z=new ZipArchive; $z->open($argv[1]); echo json_decode($z->getFromName("content.json"),true)["format"];' "$WORK/export.zip")"

echo
echo "SCHEMAT STRONY — HTTP: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
