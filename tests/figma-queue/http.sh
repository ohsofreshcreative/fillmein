#!/usr/bin/env bash
# Dodawanie podstron bez czekania na Figmę (JSON i zwykły formularz), kolejka Figmy i masowy import: odpowiedzi,
# lista podstron, uprawnienia (404 / 419).

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-figma-queue-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a)
PB_PAGE=$(json "$MANIFEST" pages.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
PLAIN=$(json "$IDS" plain)
LINKED=$(json "$IDS" linked)
BASE="$SITE/app/projects/$PA"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -qF -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }

WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmi-queue.XXXXXX"); chmod 700 "$WORK"
JAR="$WORK/jar"
trap 'rm -rf "$WORK"' EXIT

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$@"; }
admin() { curl -s --max-time 60 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$@"; }
meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
api() { curl -s --max-time 60 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" -H 'Accept: application/json' -X POST "$@"; }
postas() { local jar page token; jar=$(mktemp "$WORK/jar.XXXXXX"); page=$(curl -s --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" "$SITE/app"); token=$(meta "$page"); curl -s -o /dev/null -w '%{http_code}' --max-time 40 -c "$jar" -b "$jar" -H "Cookie: $1" -H "X-CSRF-TOKEN: $token" -H 'Accept: application/json' -X POST "$2"; }
field() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d) ? json_encode($d[$argv[1]] ?? null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : "brak-json";' "$1"; }

echo "== Lista podstron =="
HTML=$(admin "$BASE"); CSRF=$(meta "$HTML")
has "Kolejka Figmy na liście" "$HTML" 'data-figma-queue'
has "Formularz dodawania w tle" "$HTML" 'data-page-add-form'
has "Masowa analiza i import" "$HTML" 'Analiza i import pól ('
has "Zaznaczanie podstrony z Figmą" "$HTML" "data-figma-bulk-page"
has "Status kolejki przy podstronie" "$HTML" "data-figma-status=\"$LINKED\""

echo "== Dodawanie podstrony bez czekania na Figmę =="
START=$(php -r 'echo microtime(true);')
BODY=$(api -o "$WORK/add.json" -w '%{http_code}' "$BASE/pages" -d 'name=Szybka+podstrona' --data-urlencode 'figma_frame_url=https://www.figma.com/design/abcdefghij12/A?node-id=5-5')
TIME=$(php -r 'echo round(microtime(true) - (float) $argv[1], 1);' "$START")
check "JSON: utworzono" 201 "$BODY"
check "Nazwa w odpowiedzi" '"Szybka podstrona"' "$(field name < "$WORK/add.json")"
# Bez skonfigurowanej Figmy nie ma czego pobierać w tle — wtedy odpowiedź mówi queued=false.
if [ "$(field queued < "$WORK/add.json")" = true ]; then
  has "Adres kolejki w odpowiedzi" "$(field fetch_url < "$WORK/add.json")" '/figma/queue/fetch'
  has "Adres porcji podglądów" "$(field previews_url < "$WORK/add.json")" '/figma/queue/previews'
else
  check "Bez Figmy: brak zadania w tle" 'null' "$(field fetch_url < "$WORK/add.json")"
fi
php -r 'exit((float) $argv[1] < 5 ? 0 : 1);' "$TIME"; check "Odpowiedź bez pobierania Figmy (< 5 s, było $TIME s)" 0 "$?"
check "Błąd walidacji w JSON" 422 "$(api -o "$WORK/err.json" -w '%{http_code}' "$BASE/pages" -d 'name=')"
has "Komunikat walidacji" "$(cat "$WORK/err.json")" '"errors"'
check "Zwykły formularz: przekierowanie" 302 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" -X POST "$BASE/pages" -d 'name=Formularz+bez+JS' --data-urlencode 'figma_frame_url=https://www.figma.com/design/abcdefghij12/A?node-id=6-6')"
HTML=$(admin "$BASE")
has "Komunikat: frame w tle" "$HTML" 'Frame z Figmy pobiera się w tle.'

echo "== Kolejka: odpowiedzi =="
CSRF=$(meta "$HTML")
check "Pobranie bez linku: 200" 200 "$(api -o "$WORK/q.json" -w '%{http_code}' "$BASE/pages/$PLAIN/figma/queue/fetch")"
check "Status błędu w JSON" '"error"' "$(field status < "$WORK/q.json")"
check "Podglądy bez frame'u: 200" 200 "$(api -o "$WORK/p.json" -w '%{http_code}' "$BASE/pages/$PLAIN/figma/queue/previews")"
check "Status podglądów w JSON" '"error"' "$(field status < "$WORK/p.json")"
check "Import bez linku: 200" 200 "$(api -o "$WORK/i.json" -w '%{http_code}' "$BASE/pages/$PLAIN/figma/queue/import")"
check "Status importu w JSON" '"error"' "$(field status < "$WORK/i.json")"

echo "== Uprawnienia =="
check "Bez CSRF" 419 "$(code -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -X POST "$BASE/pages/$PLAIN/figma/queue/fetch")"
check "Pobranie: spoza projektu" 404 "$(postas "$C_OUTSIDER" "$BASE/pages/$PLAIN/figma/queue/fetch")"
check "Podglądy: spoza projektu" 404 "$(postas "$C_OUTSIDER" "$BASE/pages/$PLAIN/figma/queue/previews")"
check "Import: spoza projektu" 404 "$(postas "$C_OUTSIDER" "$BASE/pages/$PLAIN/figma/queue/import")"
check "Podstrona innego projektu" 404 "$(api -o /dev/null -w '%{http_code}' "$BASE/pages/$PB_PAGE/figma/queue/import")"
# Pracownik przypisany do projektu ma fmi_manage_pages i fmi_configure_fields (jak przy ręcznym imporcie).
check "Przypisany pracownik: kolejka dostępna" 200 "$(postas "$C_MEMBER" "$BASE/pages/$PLAIN/figma/queue/import")"

echo
echo "FIGMA — kolejka HTTP: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
