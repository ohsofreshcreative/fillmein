#!/usr/bin/env bash
#
# Design Review, etap 07 — pinezki i komentarze: serwis (reguły), HTTP (walidacja, XSS, odmowy, CSRF, limit),
# przeglądarka (pozycja pinezki przy resize, zoomie, orientacji, przewijaniu; zmiana ekranu; marginesy; brak ekranu).
# Wymaga połączenia z Figmą (ekrany przez API) i FMI_FIGMA_LIVE_URL. Dane zawsze sprzątane.
#
# Użycie: bash tests/review-comments/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2
fmi_load_secrets
[ -n "${FMI_FIGMA_LIVE_URL:-}" ] || { echo "BLOCKED: brak FMI_FIGMA_LIVE_URL"; exit 2; }
[ -d /tmp/fmi-ui-audit/node_modules/playwright ] || { echo "BLOCKED: brak Playwrighta w /tmp/fmi-ui-audit"; exit 2; }

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-review-comments.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmirc.XXXXXX"); chmod 700 "$WORK"
B="$WORK/b"; JAR="$WORK/jar"

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f "$IDS"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-comments/fixtures.php" || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_bool($m)?($m?"1":"0"):(is_scalar($m)?$m:json_encode($m));' "$1" "$2"; }
SITE=$(json "$MANIFEST" site)
MANAGER=$(json "$MANIFEST" cookies.manager); MEMBER=$(json "$MANIFEST" cookies.member); OUTSIDER=$(json "$MANIFEST" cookies.outsider); ADMIN=$(json "$MANIFEST" cookies.admin)
L=$(json "$IDS" l); F=$(json "$IDS" f); A=$(json "$IDS" a); S1=$(json "$IDS" s1); S3=$(json "$IDS" s3); FS=$(json "$IDS" foreign_screen)
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
get() { curl -s -o "$B" -w '%{http_code}' --max-time 60 -b "$JAR" -c "$JAR" -H "Cookie: $1" "$SITE$2"; }
csrf() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
post() { local c="$1" t="$2" path="$3" data="$4"; curl -s -o "$B" -D "$WORK/h" -w '%{http_code}' --max-time 60 -b "$JAR" -c "$JAR" -H "Cookie: $c" -H "X-CSRF-TOKEN: $t" -H 'Content-Type: application/json' -H 'Accept: application/json' -X POST --data "$data" "$SITE$path"; }

echo "== Serwis =="
fmiwp eval-file "$THEME/tests/review-comments/service.php"; ok "Reguły serwisu" $?

echo "== Ekrany przez API Figmy =="
check "s1 (frame z linku) ma potwierdzoną powierzchnię" 1 "$(json "$IDS" s1_confirmed)"
echo "  INFO  s1 $(json "$IDS" s1_size), s2: $( [ -n "$(json "$IDS" s2)" ] && echo "potwierdzony=$(json "$IDS" s2_confirmed)" || echo brak )"
check "Obraz ekranu s1 (członek)" 200 "$(get "$MEMBER" "/app/design-review/$L/screens/$S1/image")"
check "Obraz ekranu: Content-Type" "image/png" "$(curl -s -o /dev/null -w '%{content_type}' -H "Cookie: $MEMBER" "$SITE/app/design-review/$L/screens/$S1/image")"
check "Obraz obcego ekranu przez projekt L → 404" 404 "$(get "$ADMIN" "/app/design-review/$L/screens/$FS/image")"
check "Obraz ekranu L dla obcego pracownika → 404" 404 "$(get "$OUTSIDER" "/app/design-review/$L/screens/$S1/image")"

echo "== Zapis uwag (HTTP) =="
get "$MEMBER" "/app/design-review/$L" >/dev/null; T=$(csrf)
C="/app/design-review/$L/comments"
[ "${DEBUG_RL:-0}" = 1 ] && trap 'grep -i "ratelimit" "$WORK/h" 2>/dev/null | tr "\r\n" "  "; echo' DEBUG
check "Członek: pinezka na s1" 201 "$(post "$MEMBER" "$T" "$C" '{"body":"<script>alert(1)</script> Kontrast","screen_id":'"$S1"',"x":0.3,"y":0.02}')"
check "Odpowiedź: numer 1, pinezka" '{"number":1,"pin":true}' "$(cat "$B")"
check "Uwaga do kontekstu bez ekranu" 201 "$(post "$MEMBER" "$T" "$C" '{"body":"Menu","context":"Nagłówek <img src=x onerror=alert(2)>"}')"
check "x poza zakresem → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","screen_id":'"$S1"',"x":1.5,"y":0.5}')"
grep -q 'poza ekranem' "$B"; ok "Komunikat: pozycja poza ekranem" $?
check "Ujemne y → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","screen_id":'"$S1"',"x":0.5,"y":-0.01}')"
check "x bez y → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","screen_id":'"$S1"',"x":0.5}')"
check "x jako tekst → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","screen_id":'"$S1"',"x":"abc","y":0.5}')"
check "Pinezka na niepotwierdzonym ekranie → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","screen_id":'"$S3"',"x":0.5,"y":0.5}')"
check "Pinezka bez ekranu → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","context":"Koszyk","x":0.5,"y":0.5}')"
check "Ekran obcego projektu → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"a","screen_id":'"$FS"'}')"
check "Pusta treść → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"   ","screen_id":'"$S1"'}')"
LONG=$(php -r 'echo str_repeat("a", 2001);')
check "Treść 2001 znaków → 422" 422 "$(post "$MEMBER" "$T" "$C" '{"body":"'"$LONG"'","screen_id":'"$S1"'}')"
check "Bez CSRF → 419" 419 "$(post "$MEMBER" "" "$C" '{"body":"a","screen_id":'"$S1"'}')"
get "$OUTSIDER" /app/design-review >/dev/null; TO=$(csrf)
check "Obcy pracownik → 404" 404 "$(post "$OUTSIDER" "$TO" "$C" '{"body":"a","context":"x"}')"
get "$ADMIN" /app/design-review >/dev/null; TA=$(csrf)
check "Projekt bez Design Review (A) → 404" 404 "$(post "$ADMIN" "$TA" "/app/design-review/$A/comments" '{"body":"a","context":"x"}')"
check "Obcy projekt F przez członka → 404" 404 "$(post "$MEMBER" "$T" "/app/design-review/$F/comments" '{"body":"a","context":"x"}')"

echo "== Wyświetlanie (XSS) =="
check "Strona recenzji" 200 "$(get "$MEMBER" "/app/design-review/$L")"
grep -qF '&lt;script&gt;alert(1)&lt;/script&gt; Kontrast' "$B"; ok "Treść escapowana" $?
grep -qF 'Nagłówek &lt;img src=x onerror=alert(2)&gt;' "$B"; ok "Kontekst escapowany" $?
! grep -q '<script>alert(1)' "$B" && ! grep -q '<img src=x' "$B"; ok "Brak surowego HTML z uwag" $?
grep -q 'id="uwaga-1"' "$B" && grep -q 'id="uwaga-2"' "$B"; ok "Lista: uwagi 1 i 2" $?

echo "== Ekrany: uprawnienia i walidacja =="
check "Członek: dodanie ekranu → 403" 403 "$(curl -s -o "$B" -w '%{http_code}' -b "$JAR" -c "$JAR" -H "Cookie: $MEMBER" -H "X-CSRF-TOKEN: $T" --data-urlencode 'screen_url=https://www.figma.com/design/abcdefghij12/X?node-id=1-2' "$SITE/app/design-review/$L/screens")"
get "$MANAGER" "/app/design-review/$L" >/dev/null; TM=$(csrf)
check "Opiekun: ekran z innego pliku → przekierowanie z błędem" 302 "$(curl -s -o "$B" -w '%{http_code}' -b "$JAR" -c "$JAR" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" --data-urlencode 'screen_url=https://www.figma.com/design/abcdefghij12/X?node-id=1-2' "$SITE/app/design-review/$L/screens")"
get "$MANAGER" "/app/design-review/$L" >/dev/null
grep -q 'Ekran musi pochodzić z tego samego pliku Figmy' "$B"; ok "Komunikat: inny plik" $?
TM=$(csrf)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" --data-urlencode 'screen_url=https://www.figma.com/design/abcdefghij12/X' "$SITE/app/design-review/$L/screens"
get "$MANAGER" "/app/design-review/$L" >/dev/null
grep -q 'Wklej link do konkretnego frame' "$B"; ok "Komunikat: link bez node-id" $?

echo "== Podstrony: lista nazwa → link =="
TM=$(csrf)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" --data-urlencode 'screens[0][name]=' --data-urlencode 'screens[0][url]=' "$SITE/app/design-review/$L/screens"
get "$MANAGER" "/app/design-review/$L" >/dev/null
grep -q 'Dodaj co najmniej jedną podstronę' "$B"; ok "Same puste wiersze → komunikat" $?
TM=$(csrf)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" --data-urlencode 'screens[0][name]=Oferta' --data-urlencode 'screens[0][url]=https://www.figma.com/design/abcdefghij12/X' --data-urlencode 'screens[1][name]=Kontakt' --data-urlencode 'screens[1][url]=https://www.figma.com/design/abcdefghij12/X?node-id=1-2' "$SITE/app/design-review/$L/screens"
get "$MANAGER" "/app/design-review/$L" >/dev/null
grep -q 'Wklej link do konkretnego frame' "$B" && grep -q 'Ekran musi pochodzić z tego samego pliku Figmy' "$B"; ok "Dwa błędne wiersze → dwa komunikaty" $?
grep 'data-subpages-form' -A8 "$B" | grep 'x-data=' | grep -q 'Oferta' && grep 'data-subpages-form' -A8 "$B" | grep 'x-data=' | grep -q 'Kontakt'; ok "Błędne wiersze wracają do formularza z nazwami" $?

echo "== Przeglądarka =="
if [ "${SKIP_PINS:-0}" = 1 ]; then echo "  SKIP  przeglądarka (SKIP_PINS=1)"; else node "$THEME/tests/review-comments/pins.mjs"; ok "Pinezki w przeglądarce" $?; fi

echo "== Limit częstotliwości =="
get "$MANAGER" "/app/design-review/$L" >/dev/null; TM=$(csrf)
LAST=""
for i in $(seq 1 25); do LAST=$(post "$MANAGER" "$TM" "$C" '{"body":"seria '"$i"'","context":"limit"}'); [ "$LAST" = 429 ] && break; done
check "Seria uwag → 429 po limicie (20/min)" 429 "$LAST"

echo
echo "PINEZKI I KOMENTARZE: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
