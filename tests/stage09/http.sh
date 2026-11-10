#!/usr/bin/env bash
# Etap 09 — testy HTTP przesyłania i serwowania plików na działającym LocalWP.
# Wymaga manifestu z tests/stage03/setup.php i zasiewu tests/stage09/seed.php. Tokeny nie trafiają na wyjście.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage09-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }
f() { json "$IDS" "fields.$1"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b); PAGE_A=$(json "$MANIFEST" pages.a)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
TOKEN_A=$(json "$IDS" tokens.a); TOKEN_B=$(json "$IDS" tokens.b); REVOKED=$(json "$IDS" tokens.revoked); EXPIRED=$(json "$IDS" tokens.expired)
PUUID=$(json "$IDS" project_uuid); FOREIGN=$(json "$IDS" assets.foreign); FX=$(json "$IDS" fixtures)
EDITOR="$SITE/p/$TOKEN_A"; EDITOR_B="$SITE/p/$TOKEN_B"
CONTENT="$SITE/app/projects/$PA/pages/$PAGE_A/content"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -qi -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -qi -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono niedozwolony fragment"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
hasntf() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono sekret"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
denied() { case "$2" in 400|403|404) echo "  PASS  $1 ($2)"; PASS=$((PASS+1));; *) echo "  FAIL  $1 — otrzymano $2"; FAIL=$((FAIL+1));; esac; }

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); SJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); OJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); BJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); HDR=$(mktemp "${TMPDIR:-/tmp}/fmi-hdr.XXXXXX"); B=$(mktemp "${TMPDIR:-/tmp}/fmi-hdr.XXXXXX")
trap 'rm -f "$JAR" "$SJAR" "$OJAR" "$BJAR" "$HDR" "$B"' EXIT

meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$@"; }
jq_() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$B" "$1"; }
uuid() { php -r '$j=json_decode(file_get_contents($argv[1]),true); preg_match("/data-sortable-item=\"([^\"]+)\"/",$j["html"]??"",$m); echo $m[1]??"";' "$B"; }

HTML=$(curl -s -c "$JAR" -b "$JAR" --max-time 25 "$EDITOR")
CSRF=$(meta "$HTML")
api() { local path="$1"; shift; curl -s -D "$HDR" -o "$B" -w '%{http_code}' --max-time 40 -c "$JAR" -b "$JAR" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CSRF" -X POST "$@" "$EDITOR/$path"; }
send() { api "fields/$1/assets" -F "base_version=$2" -F "file=@$FX/$3;filename=${4:-$3}"; }

PANEL=$(curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$CONTENT")
SCSRF=$(meta "$PANEL")
sapi() { local path="$1"; shift; curl -s -o "$B" -w '%{http_code}' --max-time 40 -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $SCSRF" -X POST "$@" "$CONTENT/$path"; }

echo "== Pole w edytorze =="
has "Pole pliku" "$HTML" 'type="file"'
has "Lista typów" "$HTML" 'accept="image/jpeg,image/png,image/webp,image/gif"'
has "Galeria wielokrotna" "$HTML" ' multiple'

echo "== Bezpieczeństwo przesyłania =="
G=$(f gallery)
check "Plik PHP jako .jpg → 422" 422 "$(send "$G" 0 php-as.jpg)"
check "SVG ze skryptem → 422" 422 "$(send "$G" 0 script.svg)"
check "Plik ponad limit → 422" 422 "$(send "$G" 0 oversized.png)"
check "BMP → 422" 422 "$(send "$G" 0 unsupported.bmp)"
check "Plik tekstowy → 422" 422 "$(send "$G" 0 note.txt)"
check "Tekst udający PNG → 422" 422 "$(send "$G" 0 note.txt udaje.png)"
check "SVG udający PNG → 422" 422 "$(send "$G" 0 script.svg udaje.png)"
check "Uszkodzony PNG → 422" 422 "$(send "$G" 0 broken.png)"
check "Bomba pikselowa → 422" 422 "$(send "$G" 0 bomb.png)"
check "Za mały obraz → 422" 422 "$(send "$(f sized)" 0 small.png)"
check "Bez pliku → 422" 422 "$(api "fields/$G/assets" -d 'base_version=0')"
check "Pole tekstowe → 422" 422 "$(send "$(f text)" 0 ok.png)"
check "Pole archiwalne → 403" 403 "$(send "$(f archived)" 0 ok.png)"
check "Pole ukryte → 403" 403 "$(send "$(f hidden)" 0 ok.png)"
check "Pole innego projektu → 404" 404 "$(send "$(f foreign)" 1 ok.png)"
check "Bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -F 'base_version=0' -F "file=@$FX/ok.png" "$EDITOR/fields/$G/assets")"

echo "== Przesyłanie i galeria =="
check "PNG" 200 "$(send "$G" 0 ok.png 'Moje zdjęcie.png')"
A1=$(uuid); hasntf "Odpowiedź bez tokenu" "$(cat "$B")" "$TOKEN_A"
hasnt "Odpowiedź bez ścieżki" "$(cat "$B")" 'original.png'
has "Odpowiedź z postępem" "$(cat "$B")" '"progress"'
check "JPG" 200 "$(send "$G" 1 ok.jpg)"; A2=$(uuid)
check "WebP" 200 "$(send "$G" 2 ok.webp)"; A3=$(uuid)
check "Ponad max zdjęć → 422" 422 "$(send "$G" 3 ok.gif)"
check "Stara wersja → 409" 409 "$(api "fields/$G/assets/$A1/detach" -d 'base_version=1')"
check "Sortowanie" 200 "$(api "fields/$G/assets/reorder" -d "base_version=3&order[]=$A3&order[]=$A1&order[]=$A2")"
check "Sortowanie niepełne → 422" 422 "$(api "fields/$G/assets/reorder" -d "base_version=4&order[]=$A3")"
check "Sortowanie obcym plikiem → 422" 422 "$(api "fields/$G/assets/reorder" -d "base_version=4&order[]=$FOREIGN")"
check "Tekst alternatywny" 200 "$(api "fields/$G/assets/$A3/alt" -d 'base_version=4' --data-urlencode 'alt=Opis z testu')"
check "Alt ponad limit → 422" 422 "$(api "fields/$G/assets/$A3/alt" -d 'base_version=4' --data-urlencode "alt=$(printf 'x%.0s' {1..501})")"
HTML=$(curl -s -b "$JAR" --max-time 25 "$EDITOR")
ORDER=$(printf '%s' "$HTML" | grep -o 'data-sortable-item="[^"]*"' | sed 's/.*="//;s/"$//' | tr '\n' ' ')
check "Kolejność w edytorze" "$A3 $A1 $A2 " "$ORDER"
has "Alt w HTML" "$HTML" 'alt="Opis z testu"'
has "Miniatura przez parametr" "$HTML" "?plik=$A3"
hasntf "HTML bez tokenu" "$HTML" "$TOKEN_A"
check "Odpięcie" 200 "$(api "fields/$G/assets/$A2/detach" -d 'base_version=4')"
check "Ponowne odpięcie → 404" 404 "$(api "fields/$G/assets/$A2/detach" -d 'base_version=5')"
check "Zdjęcie główne" 200 "$(send "$(f image)" 0 second.png)"; IMG=$(uuid)
check "Alt zdjęcia głównego" 200 "$(api "fields/$(f image)/assets/$IMG/alt" -d 'base_version=1' --data-urlencode 'alt=Zdjęcie główne')"
check "Panel: przesłanie" 200 "$(sapi "fields/$(f hidden)/assets" -F 'base_version=0' -F "file=@$FX/third.png")"; STAFF=$(uuid)
has "Panel: adres trasy" "$(cat "$B")" "projects"

printf '{"gallery_order":["%s","%s"],"detached":"%s"}' "$A3" "$A1" "$A2" > /tmp/fmi-stage09-http.json

echo "== IDOR =="
check "Plik B: odpięcie tokenem A → 404" 404 "$(api "fields/$G/assets/$FOREIGN/detach" -d 'base_version=5')"
check "Plik B: alt tokenem A → 404" 404 "$(api "fields/$G/assets/$FOREIGN/alt" -d 'base_version=5&alt=x')"
check "Pole B: odpięcie tokenem A → 404" 404 "$(api "fields/$(f foreign)/assets/$FOREIGN/detach" -d 'base_version=1')"
check "Panel: pole B → 404" 404 "$(sapi "fields/$(f foreign)/assets/$FOREIGN/detach" -d 'base_version=1')"
check "Odpięcie bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d 'base_version=5' "$EDITOR/fields/$G/assets/$A1/detach")"
check "Sortowanie bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d "base_version=5&order[]=$A1" "$EDITOR/fields/$G/assets/reorder")"
check "Alt bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d 'base_version=5&alt=x' "$EDITOR/fields/$G/assets/$A1/alt")"
check "Panel: przesłanie bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -F 'base_version=0' -F "file=@$FX/ok.png" "$CONTENT/fields/$G/assets")"

echo "== Serwowanie =="
ASSET_URL="$SITE/app/projects/$PA/assets/$A3"
curl -s -D "$HDR" -o "$B" --max-time 25 "$EDITOR?plik=$A3&wariant=thumb"
H=$(tr -d '\r' < "$HDR")
has "Klient: 200" "$H" ' 200'
has "Content-Type z bazy" "$H" '^content-type: image/webp'
has "nosniff" "$H" '^x-content-type-options: nosniff'
has "inline" "$H" '^content-disposition: inline'
has "Cache prywatny" "$H" '^cache-control:.*private'
hasnt "Cache bez publicznego" "$H" '^cache-control:.*public'
has "Referrer-Policy" "$H" '^referrer-policy: no-referrer'
hasntf "Nagłówki bez tokenu" "$H" "$TOKEN_A"
check "Klient: oryginał" 200 "$(code "$EDITOR?plik=$A3&wariant=original")"
check "Klient: podgląd" 200 "$(code "$EDITOR?plik=$A3&wariant=preview")"
check "Klient: zły wariant → 404" 404 "$(code "$EDITOR?plik=$A3&wariant=..%2F..%2Fsecret")"
check "Klient: zły identyfikator → 404" 404 "$(code "$EDITOR?plik=..%2F..%2Fwp-config.php")"
check "Klient: plik odpięty nadal dostępny w projekcie" 200 "$(code "$EDITOR?plik=$A2&wariant=thumb")"
check "Klient A: plik B → 404" 404 "$(code "$EDITOR?plik=$FOREIGN&wariant=thumb")"
check "Klient B: plik A → 404" 404 "$(code "$EDITOR_B?plik=$A3&wariant=thumb")"
check "Token unieważniony → 404" 404 "$(code "$SITE/p/$REVOKED?plik=$A3&wariant=thumb")"
check "Token wygasły → 404" 404 "$(code "$SITE/p/$EXPIRED?plik=$A3&wariant=thumb")"
check "Administrator" 200 "$(code -H "Cookie: $C_ADMIN" "$ASSET_URL/thumb")"
check "Pracownik projektu" 200 "$(code -H "Cookie: $C_MEMBER" "$ASSET_URL/original")"
check "Pracownik spoza projektu → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$ASSET_URL/thumb")"
check "Gość → logowanie" 302 "$(code "$ASSET_URL/thumb")"
check "Plik A pod projektem B → 404" 404 "$(code -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PB/assets/$A3/thumb")"
check "Panel: nieznany wariant → 404" 404 "$(code -H "Cookie: $C_ADMIN" "$ASSET_URL/secret")"
curl -s -D "$HDR" -o "$B" --max-time 25 -H "Cookie: $C_ADMIN" "$ASSET_URL/original"
H=$(tr -d '\r' < "$HDR")
has "Panel: nosniff" "$H" '^x-content-type-options: nosniff'
has "Panel: cache prywatny" "$H" '^cache-control:.*private'

echo "== Bezpośrednie adresy =="
for REL in "fmi-storage/projects/$PUUID/$A3/original.webp" "fmi-storage/.htaccess" "fmi-storage/" "wp-content/uploads/fmi-private/$PUUID/original.webp" "wp-content/uploads/fmi-private/"; do
  denied "Bezpośredni URL ${REL%%/*}/…" "$(code "$SITE/$REL")"
done
denied "Wyjście z katalogu publicznego" "$(code --path-as-is "$SITE/../fmi-storage/projects/$PUUID/$A3/original.webp")"
denied "Wyjście z katalogu publicznego (zakodowane)" "$(code --path-as-is "$SITE/%2e%2e/fmi-storage/projects/$PUUID/$A3/original.webp")"

echo
echo "HTTP etapu 09: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
