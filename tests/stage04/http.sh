#!/usr/bin/env bash
# Etap 04 — testy HTTP tras Figmy na działającym LocalWP (http://fillmein.local).
# Wymaga manifestu z tests/stage03/setup.php i zasiewu tests/stage04/seed.php.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
[ -f "$MANIFEST" ] || { echo "BLOCKED: brak $MANIFEST"; exit 2; }

json() { php -r '$m=json_decode(file_get_contents("/tmp/fmi-stage03-fixtures.json"),true); foreach(explode(".",$argv[1]) as $k){$m=$m[$k];} echo $m;' "$1"; }

SITE=$(json site)
PA=$(json projects.a); PB=$(json projects.b)
PAGE_A=$(json pages.a); PAGE_B=$(json pages.b)
C_ADMIN=$(json cookies.admin); C_MEMBER=$(json cookies.member); C_OUTSIDER=$(json cookies.outsider)
PREV="$SITE/app/projects/$PA/pages/$PAGE_A/figma/preview"
FETCH="$SITE/app/projects/$PA/pages/$PAGE_A/figma"

PASS=0; FAIL=0
code() { if [ "$1" = "-" ]; then curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$2"; else curl -s -o /dev/null -w '%{http_code}' --max-time 25 -H "Cookie: $1" "$2"; fi; }
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if printf '%s' "$2" | grep -q -- "$3"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if printf '%s' "$2" | grep -q -- "$3"; then echo "  FAIL  $1 — znaleziono: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

echo "== Podgląd z prywatnego cache =="
check "Gość → przekierowanie na logowanie" 302 "$(code - "$PREV/1-2")"
check "Administrator: podgląd frame'u" 200 "$(code "$C_ADMIN" "$PREV/1-2")"
check "Administrator: podgląd warstwy-dziecka" 200 "$(code "$C_ADMIN" "$PREV/1-20")"
check "Przypisany pracownik: podgląd" 200 "$(code "$C_MEMBER" "$PREV/1-2")"
check "Pracownik bez przypisania → 404" 404 "$(code "$C_OUTSIDER" "$PREV/1-2")"
check "Warstwa spoza frame'u (9-9) → 404" 404 "$(code "$C_ADMIN" "$PREV/9-9")"
check "Ukryta warstwa (1-40) bez podglądu → 404" 404 "$(code "$C_ADMIN" "$PREV/1-40")"
# Adres niepasujący do wzorca trasy nie trafia do aplikacji — front WordPressa przekierowuje na /app
# (to samo zachowanie co /p/<zły format> w audycie 00). Sprawdzamy, że nie dostajemy obrazu.
loc() { curl -s -o /dev/null -w '%{http_code} %{redirect_url} %{content_type}' --max-time 25 -H "Cookie: $1" "$2"; }
check "Niepoprawne ID (..%2F) → poza trasą, przekierowanie na /app" "302 $SITE/app text/html; charset=UTF-8" "$(loc "$C_ADMIN" "$PREV/..%2F..%2Fx")"
check "ID z dwukropkiem → poza trasą, przekierowanie na /app" "302 $SITE/app text/html; charset=UTF-8" "$(loc "$C_ADMIN" "$PREV/1:2")"
check "Podstrona B pod projektem A → 404 (scopeBindings)" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_B/figma/preview/1-2")"
check "Podstrona A pod projektem B → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PB/pages/$PAGE_A/figma/preview/1-2")"
check "Podstrona B bez frame'u → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PB/pages/$PAGE_B/figma/preview/1-2")"

HEADERS=$(curl -s -D - -o /tmp/fmi-stage04-preview.bin --max-time 25 -H "Cookie: $C_ADMIN" "$PREV/1-2")
has "Content-Type: image/png" "$HEADERS" "[Cc]ontent-[Tt]ype: image/png"
has "Cache-Control: private" "$HEADERS" "[Cc]ache-[Cc]ontrol: .*private"
has "X-Content-Type-Options: nosniff" "$HEADERS" "nosniff"
check "Treść to prawdziwy PNG z cache (24×16)" "24x16" "$(php -r '$i=getimagesize("/tmp/fmi-stage04-preview.bin"); echo $i?$i[0]."x".$i[1]:"brak";')"
rm -f /tmp/fmi-stage04-preview.bin

echo "== Widok edycji podstrony =="
HTML=$(curl -s --max-time 25 -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")
has "Karta „Frame z Figmy”" "$HTML" "Frame z Figmy"
has "Nazwa frame'u z cache (escaping)" "$HTML" "Strona główna — desktop"
has "Wersja pliku z cache" "$HTML" "1111111111"
has "Komunikat o braku tokenu" "$HTML" "Brak tokenu Figma API"
has "Obraz z trasy aplikacji" "$HTML" "/figma/preview/1-2"
has "Podglądy warstw najwyższego poziomu" "$HTML" "Warstwy najwyższego poziomu (3)"
hasnt "Podpisany adres obrazu nie trafia do HTML" "$HTML" "amazonaws"
hasnt "Brak ukrytej warstwy w podglądach" "$HTML" "Szkic (ukryty)"
hasnt "Brak wartości tokenu/stałej w HTML" "$HTML" "figd_"

echo "== Pobranie z Figmy (POST) =="
check "POST bez tokenu CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST -H "Cookie: $C_ADMIN" "$FETCH")"

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
PAGE_HTML=$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")
TOKEN=$(printf '%s' "$PAGE_HTML" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$TOKEN" ]; then
  CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "Referer: $SITE/app/projects/$PA/pages/$PAGE_A/edit" -X POST -d "_token=$TOKEN&force=1" "$FETCH")
  check "POST z CSRF (admin, bez tokenu API) → przekierowanie" 302 "$CODE"
  AFTER=$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")
  has "Czytelny komunikat błędu zamiast 500" "$AFTER" "Dodaj stałą FMI_FIGMA_TOKEN"
  has "Cache zachowany po nieudanym pobraniu" "$AFTER" "1111111111"
else
  echo "  FAIL  brak tokenu CSRF w formularzu"; FAIL=$((FAIL+1))
fi
rm -f "$JAR"

OJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
O_HTML=$(curl -s --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" "$SITE/app/projects")
O_TOKEN=$(printf '%s' "$O_HTML" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$O_TOKEN" ]; then
  check "POST pracownika bez przypisania (z ważnym CSRF) → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" -X POST -d "_token=$O_TOKEN" "$FETCH")"
else
  echo "  FAIL  brak tokenu CSRF w sesji pracownika bez przypisania"; FAIL=$((FAIL+1))
fi
rm -f "$OJAR"

echo
echo "HTTP etapu 04: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
