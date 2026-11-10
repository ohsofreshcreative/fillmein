#!/usr/bin/env bash
# Eksport — zakładka: zakres (cały projekt / wybrane podstrony), walidacja, ponowne generowanie, pobieranie content.json,
# uprawnienia. Token klienta nie trafia na wyjście.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage11-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
TOKEN=$(json "$IDS" token)
EXPORTS="$SITE/app/projects/$PA/exports"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -qF -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasntf() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono wartość zabronioną"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmi-export.XXXXXX"); chmod 700 "$WORK"
JAR="$WORK/jar"
trap 'rm -rf "$WORK"' EXIT

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$@"; }
admin() { curl -s --max-time 60 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$@"; }
meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
post() { curl -s -o /dev/null -w '%{http_code}' --max-time 120 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" -X POST "$@"; }
postas() { local jar page token; jar=$(mktemp "$WORK/jar.XXXXXX"); page=$(curl -s --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" "$SITE/app"); token=$(meta "$page"); curl -s -o /dev/null -w '%{http_code}' --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" -H "X-CSRF-TOKEN: $token" -X POST "$2"; }
items() { printf '%s' "$1" | grep -c 'data-export-item='; }

echo "== Zakładka Eksport =="
HTML=$(admin "$EXPORTS"); CSRF=$(meta "$HTML")
has "Zakładki modułu Treści" "$HTML" 'data-content-tabs'
has "Zakładka aktywna" "$HTML" 'aria-current="page"'
has "Wybór zakresu" "$HTML" 'Wybrane podstrony'
has "Podsumowanie" "$HTML" 'data-export-summary'
has "Przycisk Eksportuj ZIP" "$HTML" 'Eksportuj ZIP'
has "Podstrona na liście" "$HTML" '>O nas<'
CHILD=$(printf '%s' "$HTML" | php -r 'preg_match("~id=\"export-page-([0-9a-f-]{36})\"(?:(?!</li>).)*?>O nas<~s", stream_get_contents(STDIN), $m); echo $m[1] ?? "";')
[ -n "$CHILD" ]; check "UUID podstrony z listy" 0 "$?"
BEFORE=$(items "$HTML")

echo "== Walidacja zakresu =="
check "Wybrane podstrony bez zaznaczenia → przekierowanie" 302 "$(post --data 'scope=pages' "$EXPORTS")"
has "Komunikat: zaznacz podstronę" "$(admin "$EXPORTS")" 'Zaznacz co najmniej jedną podstronę.'
CSRF=$(meta "$(admin "$EXPORTS")")
check "Nieznana podstrona → przekierowanie" 302 "$(post --data 'scope=pages&pages[]=00000000-0000-4000-8000-000000000000' "$EXPORTS")"
has "Komunikat: spoza projektu" "$(admin "$EXPORTS")" 'nie istnieje w tym projekcie'
CSRF=$(meta "$(admin "$EXPORTS")")
check "Zły zakres → przekierowanie" 302 "$(post --data 'scope=wszystko' "$EXPORTS")"
CSRF=$(meta "$(admin "$EXPORTS")")
check "Bez nowego eksportu po błędach" "$BEFORE" "$(items "$(admin "$EXPORTS")")"

echo "== Eksport wybranej podstrony =="
CSRF=$(meta "$(admin "$EXPORTS")")
check "Eksport podstrony O nas" 302 "$(post --data "scope=pages&pages[]=$CHILD" "$EXPORTS")"
HTML=$(admin "$EXPORTS")
has "Komunikat gotowości" "$HTML" 'Eksport gotowy'
has "Zakres w historii" "$HTML" 'Podstrony: O nas'
has "Wersja formatu w historii" "$HTML" "format 2.0"
check "Nowa pozycja w historii" "$((BEFORE+1))" "$(items "$HTML")"
UUID=$(printf '%s' "$HTML" | grep -o "/exports/[0-9a-f-]\{36\}/content" | head -1 | sed 's~/exports/~~;s~/content~~')
check "Pobranie content.json" 200 "$(curl -s -o "$WORK/content.json" -w '%{http_code}' --max-time 40 -H "Cookie: $C_ADMIN" "$EXPORTS/$UUID/content")"
check "content.json: format i zakres" "fmi-content pages 1 null" "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["format"]," ",$d["scope"]["type"]," ",count($d["pages"])," ",json_encode($d["pages"][0]["parent_id"]);' "$WORK/content.json")"
hasntf "content.json bez tokenu klienta" "$(cat "$WORK/content.json")" "$TOKEN"
check "Pobranie ZIP" 200 "$(curl -s -o "$WORK/export.zip" -w '%{http_code}' --max-time 40 -H "Cookie: $C_ADMIN" "$EXPORTS/$UUID/zip")"
check "ZIP: rozpakowanie i odczyt" "ok" "$(php -r '$z=new ZipArchive; if($z->open($argv[1])!==true){exit("open");} $d=json_decode($z->getFromName("content.json"),true); if(!is_array($d)){exit("json");} foreach($d["media"] as $m){ if(hash("sha256",(string)$z->getFromName($m["path"]))!==$m["sha256"]){exit("sha");} } echo "ok";' "$WORK/export.zip")"
check "content.json: spoza projektu" 404 "$(code -H "Cookie: $C_OUTSIDER" "$EXPORTS/$UUID/content")"
check "content.json: przypisany bez capability" 403 "$(code -H "Cookie: $C_MEMBER" "$EXPORTS/$UUID/content")"

echo "== Ponowne generowanie =="
CSRF=$(meta "$(admin "$EXPORTS")")
check "Bez CSRF" 419 "$(code -H "Cookie: $C_ADMIN" -X POST "$EXPORTS/$UUID/regenerate")"
check "Przypisany bez capability" 403 "$(postas "$C_MEMBER" "$EXPORTS/$UUID/regenerate")"
check "Spoza projektu" 404 "$(postas "$C_OUTSIDER" "$EXPORTS/$UUID/regenerate")"
check "Nieznany eksport" 404 "$(post "$EXPORTS/00000000-0000-4000-8000-000000000000/regenerate")"
CSRF=$(meta "$(admin "$EXPORTS")")
check "Wygeneruj ponownie" 302 "$(post "$EXPORTS/$UUID/regenerate")"
HTML=$(admin "$EXPORTS")
check "Kolejna pozycja w historii" "$((BEFORE+2))" "$(items "$HTML")"
check "Ten sam zakres w nowym eksporcie" "Podstrony: O nas" "$(printf '%s' "$HTML" | grep -o 'data-export-scope-label>[^<]*' | head -1 | sed 's/data-export-scope-label>//')"

echo
echo "EKSPORT — zakładka: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
