#!/usr/bin/env bash
# Strona ustawień Figmy przez HTTP: uprawnienia, CSRF, brak wycieku sekretu, ochrona powrotu z Figmy.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$MANIFEST" "$1"; }

SITE=$(json site); C_ADMIN=$(json cookies.admin); C_MANAGER=$(json cookies.manager); C_MEMBER=$(json cookies.member)
URL="$SITE/app/settings/figma"
SECRET=SEKRET_HTTP_DO_NOT_LEAK
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmifig.XXXXXX")
JAR="$WORK/jar"; HDR="$WORK/h"; B="$WORK/b"
PASS=0; FAIL=0

check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -qF -- "$3" "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -qF -- "$3" "$2"; then echo "  FAIL  $1"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$@"; }
admin() { curl -s -L -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" --max-time 25 -o "$B" -w '%{http_code}' "$1"; }
post() { local path="$1"; shift; curl -s -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" --max-time 25 -D "$HDR" -o "$B" -w '%{http_code}' -X POST "$@" "$URL$path"; }
location() { grep -i '^location:' "$HDR" | tr -d '\r' | sed 's/^[^ ]* //' | tail -1; }
param() { php -r 'parse_str((string) parse_url($argv[1], PHP_URL_QUERY), $q); echo $q[$argv[2]] ?? "";' "$1" "$2"; }

echo "== Uprawnienia =="
check "Gość → logowanie" 302 "$(code "$URL")"
check "Opiekun bez uprawnienia → 403" 403 "$(code -H "Cookie: $C_MANAGER" "$URL")"
check "Pracownik → 403" 403 "$(code -H "Cookie: $C_MEMBER" "$URL")"
check "Powrót: opiekun → 403" 403 "$(code -H "Cookie: $C_MANAGER" "$URL/callback?code=x&state=y")"
check "Administrator" 200 "$(admin "$URL")"
has "Adres powrotu w widoku" "$B" "$URL/callback"
has "Bez połączenia" "$B" "Nie połączono"
CSRF=$(grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//')

echo "== CSRF =="
for path in "" /connect /disconnect; do check "Bez CSRF: POST $path" 419 "$(code -H "Cookie: $C_ADMIN" -X POST "$URL$path")"; done

echo "== Zapis danych aplikacji =="
check "Bez sekretu → przekierowanie z błędem" 302 "$(post '' -d 'client_id=client123')"
check "Połącz bez danych → przekierowanie" 302 "$(post /connect)"
has "Połącz bez danych: powrót do ustawień" "$HDR" "/app/settings/figma"
check "Zły Client ID" 302 "$(post '' -d 'client_id=z%20spacja' -d "client_secret=$SECRET")"
check "Zapis" 302 "$(post '' -d 'client_id=client123' -d "client_secret=$SECRET")"
check "Widok po zapisie" 200 "$(admin "$URL")"
has "Client ID widoczny" "$B" 'value="client123"'
has "Sekret oznaczony jako zapisany" "$B" "Sekret jest zapisany"
hasnt "Sekret nie trafia do HTML" "$B" "$SECRET"

echo "== Logowanie =="
check "Połącz → przekierowanie" 302 "$(post /connect)"
TARGET=$(location)
case "$TARGET" in https://www.figma.com/oauth\?*) echo "  PASS  Przekierowanie do Figmy"; PASS=$((PASS+1)) ;; *) echo "  FAIL  Zły adres przekierowania"; FAIL=$((FAIL+1)) ;; esac
check "Adres powrotu w żądaniu" "$URL/callback" "$(param "$TARGET" redirect_uri)"
check "Client ID w żądaniu" client123 "$(param "$TARGET" client_id)"
hasnt "Sekret poza adresem" "$HDR" "$SECRET"
STATE=$(param "$TARGET" state)
[ "${#STATE}" -ge 32 ] && check "Stan losowy" ok ok || check "Stan losowy" ok "za krótki"

echo "== Powrót z Figmy =="
back() { curl -s -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" --max-time 25 -D "$HDR" -o /dev/null -w '%{http_code}' "$URL/callback$1"; }
check "Zły stan" 302 "$(back '?code=abc&state=zly')"
check "Komunikat o błędzie" 200 "$(admin "$URL")"
has "Czytelny błąd zamiast 500" "$B" "Nie udało się potwierdzić logowania"
has "Nadal bez połączenia" "$B" "Nie połączono"
check "Stan po próbie jest jednorazowy" 302 "$(back "?code=abc&state=$STATE")"
admin "$URL" >/dev/null
has "Zużyty stan odrzucony" "$B" "Nie udało się potwierdzić logowania"

CSRF=$(grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//')
check "Ponowne połączenie" 302 "$(post /connect)"
STATE=$(param "$(location)" state)
check "Odmowa w Figmie: stan poprawny, bez kodu" 302 "$(back "?error=access_denied&state=$STATE")"
admin "$URL" >/dev/null
has "Komunikat o odmowie" "$B" "Figma nie potwierdziła połączenia"
hasnt "Sekret nie trafia do HTML po błędach" "$B" "$SECRET"

rm -f "$JAR" "$HDR" "$B"
rmdir "$WORK" 2>/dev/null
echo "FIGMA OAUTH — HTTP: $PASS/$((PASS+FAIL)) PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
