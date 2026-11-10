#!/usr/bin/env bash
#
# Etap 14 — przepływy E2E na jednym projekcie, w kolejności pracy: pracownik do wydania linku, klient, pracownik do
# importu i sprzątania WordPressa. Zapisy idą przez HTTP z sesją i CSRF, Figma przez serwisy w CLI (token jest tylko
# tam). Link Figmy i token klienta nie są wypisywane.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap >/dev/null 2>&1 || exit 2

SITE=http://fillmein.local
MANIFEST=/tmp/fmi-stage03-fixtures.json
STATE=/tmp/fmi-stage14-e2e.json
WP="$THEME/tests/bin/wp.sh"
FX="$THEME/tests/stage09/fixtures"
XSS='<img src=x onerror=alert(1)>'
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmi14e2e.XXXXXX")
chmod 700 "$WORK"
JAR="$WORK/staff"; CJAR="$WORK/client"; HDR="$WORK/h"; B="$WORK/b"
PASS=0; FAIL=0

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$1" "$2"; }
C_ADMIN=$(json "$MANIFEST" cookies.admin)
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
meta() { grep -o 'name="csrf-token" content="[^"]*"' "$1" | head -1 | sed 's/.*content="//;s/"$//'; }
get() { curl -s -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" --max-time 40 -D "$HDR" -o "$B" -w '%{http_code}' "$SITE$1"; }
post() { local path="$1"; shift; curl -s -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF" --max-time 40 -D "$HDR" -o "$B" -w '%{http_code}' -X POST "$@" "$SITE$path"; }
location() { grep -i '^location:' "$HDR" | tr -d '\r' | sed 's/^[^ ]* //' | tail -1; }
state() { fmiwp eval-file "$THEME/tests/stage14/e2e.php" state "$PROJECT" 2>/dev/null; }
field() { json "$STATE" "fields.$1"; }
page() { php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["pages"][(int)$argv[2]];' "$STATE" "$1"; }

echo "== Pracownik: projekt, podstrony, Figma =="
check "Formularz projektu" 200 "$(get /app/projects/create)"
CSRF=$(meta "$B")
check "Utworzenie projektu" 302 "$(post /app/projects --data-urlencode "name=TEST E2E $XSS" -d 'client_name=Klient+E2E')"
PROJECT=$(location | sed -E 's~.*/projects/([0-9]+).*~\1~')
[[ "$PROJECT" =~ ^[0-9]+$ ]] || { echo "BLOCKED: brak ID projektu."; exit 2; }
fmiwp eval-file "$THEME/tests/stage14/e2e.php" register "$PROJECT"
P=/app/projects/$PROJECT
check "Karta projektu" 200 "$(get "$P")"
! grep -qF "$XSS" "$B"; ok "XSS w nazwie projektu jest escapowany" $?
check "Podstrona z frame'em" 302 "$(post "$P/pages" -d 'name=Strona+główna' --data-urlencode "figma_frame_url=$FMI_FIGMA_LIVE_URL")"
state; HOME_PAGE=$(page 0)
check "Podstrona ręczna z rodzicem" 302 "$(post "$P/pages" -d 'name=Oferta' -d "parent_id=$HOME_PAGE")"
state; SUB_PAGE=$(page 1)
fmiwp eval-file "$THEME/tests/stage14/e2e.php" figma "$PROJECT" 2>/dev/null; ok "Figma: analiza i import" $?
check "Podgląd importu po synchronizacji" 200 "$(get "$P/pages/$HOME_PAGE/figma/import")"

echo "== Pracownik: pola ręczne i repeater =="
S="$P/pages/$SUB_PAGE"
check "Struktura podstrony" 200 "$(get "$S/structure")"
CSRF=$(meta "$B")
check "Sekcja ręczna" 302 "$(post "$S/sections" -d 'name=Treść+E2E')"
state; SECTION=$(json "$STATE" 'sections.Treść E2E')
add() { post "$S/sections/$SECTION/fields" "$@"; }
check "Pole: tekst" 302 "$(add -d 'type=text' -d 'label=Nagłówek E2E' -d 'is_required=1')"
check "Pole: tekst wielowierszowy" 302 "$(add -d 'type=textarea' -d 'label=Wstęp E2E')"
check "Pole: WYSIWYG" 302 "$(add -d 'type=wysiwyg' -d 'label=Opis E2E')"
check "Pole: link" 302 "$(add -d 'type=link' -d 'label=Przycisk E2E' -d 'is_required=1')"
check "Pole: zdjęcie" 302 "$(add -d 'type=image' -d 'label=Zdjęcie E2E' -d 'is_required=1')"
check "Pole: galeria" 302 "$(add -d 'type=gallery' -d 'label=Galeria E2E' -d 'is_required=1' -d 'settings[min_items]=2')"
check "Pole: grupa" 302 "$(add -d 'type=group' -d 'label=Grupa E2E')"
check "Pole: repeater" 302 "$(add -d 'type=repeater' -d 'label=Lista E2E' -d 'is_required=1')"
state
check "Pole w grupie" 302 "$(add -d 'type=text' -d 'label=W grupie E2E' -d "parent_id=$(field 'Grupa E2E')")"
check "Pole w repeaterze" 302 "$(add -d 'type=text' -d 'label=W wierszu E2E' -d 'is_required=1' -d "parent_id=$(field 'Lista E2E')")"
state

echo "== Pracownik: link klienta =="
check "Karta projektu" 200 "$(get "$P")"
CSRF=$(meta "$B")
check "Wydanie linku → powrót do projektu" 302 "$(post "$P/client-access" -d 'label=E2E')"
check "Karta projektu z linkiem" 200 "$(get "$P")"
TOKEN=$(grep -oE '/p/[A-Za-z0-9_-]{43}' "$B" | head -1 | sed 's~/p/~~')
[ -n "$TOKEN" ] || { echo "BLOCKED: brak linku klienta."; exit 2; }
ok "Link widoczny na stałe w karcie „Linki dla klienta”" "$(grep -q 'data-client-access-url' "$B"; echo $?)"

echo "== Klient: wypełnianie =="
E="/p/$TOKEN"
check "Edytor klienta" 200 "$(curl -s -c "$CJAR" -b "$CJAR" --max-time 40 -D "$HDR" -o "$B" -w '%{http_code}' "$SITE$E?strona=$SUB_PAGE")"
CCSRF=$(meta "$B")
grep -qi '^referrer-policy: no-referrer' "$HDR"; ok "Nagłówek Referrer-Policy: no-referrer" $?
grep -qi '^x-robots-tag: noindex' "$HDR"; ok "Nagłówek X-Robots-Tag: noindex" $?
grep -qi '^x-content-type-options: nosniff' "$HDR"; ok "Nagłówek nosniff" $?
grep -qi '^x-frame-options: sameorigin' "$HDR"; ok "Nagłówek X-Frame-Options" $?
! grep -qF "$XSS" "$B"; ok "XSS w edytorze klienta jest escapowany" $?
api() { local path="$1"; shift; curl -s -c "$CJAR" -b "$CJAR" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CCSRF" --max-time 40 -o "$B" -w '%{http_code}' -X POST "$@" "$SITE$E/$path"; }
V() { json "$B" version; }
check "Zapis tekstu" 200 "$(api "fields/$(field 'Nagłówek E2E')" -d 'base_version=0' --data-urlencode 'value=Nagłówek E2E od klienta')"
check "Zapis wielowierszowego" 200 "$(api "fields/$(field 'Wstęp E2E')" -d 'base_version=0' --data-urlencode $'value=Pierwszy E2E\nDrugi')"
check "Zapis WYSIWYG ze skryptem" 200 "$(api "fields/$(field 'Opis E2E')" -d 'base_version=0' --data-urlencode 'value=<p>Opis E2E</p><script>alert(1)</script>')"
check "Zapis linku" 200 "$(api "fields/$(field 'Przycisk E2E')" -d 'base_version=0' -d 'label=Kontakt+E2E' --data-urlencode 'url=https://example.com/e2e')"
check "Link javascript: odrzucony" 422 "$(api "fields/$(field 'Przycisk E2E')" -d 'base_version=1' -d 'label=x' --data-urlencode 'url=javascript:alert(1)')"
check "Pole w grupie" 200 "$(api "fields/$(field 'W grupie E2E')" -d 'base_version=0' --data-urlencode 'value=Grupa E2E')"

echo "== Klient: autozapis i konflikt =="
check "Autozapis 1" 200 "$(api "fields/$(field 'Wstęp E2E')" -d 'base_version=1' --data-urlencode 'value=Autozapis E2E 1')"
check "Autozapis 2" 200 "$(api "fields/$(field 'Wstęp E2E')" -d 'base_version=2' --data-urlencode 'value=Autozapis E2E 2')"
check "Wymuszony konflikt: stara wersja" 409 "$(api "fields/$(field 'Wstęp E2E')" -d 'base_version=1' --data-urlencode 'value=Stara karta')"
check "Konflikt zwraca aktualną wersję" 3 "$(json "$B" current.version)"
check "Rozwiązanie konfliktu" 200 "$(api "fields/$(field 'Wstęp E2E')" -d 'base_version=3' --data-urlencode 'value=Po konflikcie E2E')"

echo "== Klient: obrazy, galeria, repeater =="
up() { api "fields/$1/assets" -F "base_version=$2" -F "file=@$FX/$3;filename=$3"; }
check "Zdjęcie" 200 "$(up "$(field 'Zdjęcie E2E')" 0 ok.png)"
check "Galeria 1" 200 "$(up "$(field 'Galeria E2E')" 0 second.png)"
check "Galeria 2" 200 "$(up "$(field 'Galeria E2E')" 1 third.png)"
FIRST=$(fmiwp eval-file "$THEME/tests/stage14/e2e-row.php" "$PROJECT" asset 2>/dev/null)
check "Tekst alternatywny" 200 "$(api "fields/$(field 'Galeria E2E')/assets/$FIRST/alt" -d 'base_version=2' --data-urlencode 'alt=Opis E2E')"
check "Plik PHP odrzucony" 422 "$(api "fields/$(field 'Zdjęcie E2E')/assets" -F 'base_version=1' -F "file=@$THEME/tests/stage14/e2e.php;filename=shell.php;type=image/png")"
check "Wiersz repeatera" 200 "$(api "fields/$(field 'Lista E2E')/rows" -d 'base_version=0')"
ROW=$(fmiwp eval-file "$THEME/tests/stage14/e2e-row.php" "$PROJECT" 2>/dev/null)
check "Pole w wierszu" 200 "$(api "fields/$(field 'W wierszu E2E')" -d 'base_version=0' -d "row=$ROW" --data-urlencode 'value=Wiersz E2E')"
fmiwp eval-file "$THEME/tests/stage14/e2e.php" progress "$PROJECT" 2>/dev/null; ok "Postęp wymaganych pól" $?

echo "== Pracownik: przegląd, mapowanie, eksport =="
state
# Filtr podstrony: import Figmy na stronie głównej daje więcej pozycji niż jedna strona przeglądu (20).
check "Przegląd treści" 200 "$(get "$P/review?strona=$SUB_PAGE")"
CSRF=$(meta "$B")
grep -q 'Nagłówek E2E od klienta' "$B"; ok "Przegląd pokazuje treść klienta" $?
! grep -qF '<script>alert(1)</script>' "$B"; ok "Skrypt z WYSIWYG nie trafia do przeglądu" $?
VALUE=$(json "$STATE" value)
check "Uwaga do treści" 302 "$(post "$P/review/$VALUE" -d 'note=Do+poprawy')"
check "Zdjęcie uwagi" 302 "$(post "$P/review/$VALUE/clear")"
check "Mapowanie" 200 "$(get "$P/mapping?strona=$SUB_PAGE")"
check "Zapis mapowania" 302 "$(post "$P/mapping/$SUB_PAGE" -d 'complete=1' -d 'page[post_type]=page' -d 'page[slug]=oferta-e2e')"
check "Eksporty" 200 "$(get "$P/exports")"
CSRF=$(meta "$B")
check "Eksport z walidacją" 302 "$(post "$P/exports")"
state
EXPORT=$(json "$STATE" export); ZIP=$(json "$STATE" zip)
[ -n "$EXPORT" ] && [ -f "$ZIP" ]; ok "Plik eksportu istnieje" $?
check "Pobranie ZIP" 200 "$(get "$P/exports/$EXPORT/zip")"

echo "== Import WordPress =="
OUT=$(bash "$WP" acorn fmi:import "$ZIP" 2>&1); CODE=$?
check "Dry-run" 0 "$CODE"
printf '%s\n' "$OUT" | grep -E '^Razem'
bash "$WP" acorn fmi:import "$ZIP" --apply >/dev/null 2>&1; check "--apply bez backupu" 4 $?
OUT=$(bash "$WP" db-backup pre-14 2>&1); check "Backup pre-14" 0 $?
BACKUP=$(printf '%s\n' "$OUT" | sed -n 's/^Plik: //p')
echo "  Backup: $BACKUP"
OUT=$(bash "$WP" acorn fmi:import "$ZIP" --apply --backup="$BACKUP" 2>&1); CODE=$?
check "Import --apply" 0 "$CODE"
printf '%s\n' "$OUT" | grep -E '^Razem'
SOURCE=$(json "$STATE" source)
fmiwp eval-file "$THEME/tests/stage14/e2e.php" imported "$SOURCE" 2>/dev/null; ok "Skutek importu w WordPressie" $?
OUT=$(bash "$WP" acorn fmi:import "$ZIP" --apply --backup="$BACKUP" 2>&1); CODE=$?
check "Drugi --apply" 0 "$CODE"
grep -q 'Razem: utworzyć 0, zmienić 0, usunąć 0' <<< "$OUT"; ok "Drugi import: 0 zmian" $?
OUT=$(bash "$WP" acorn fmi:import:cleanup --source="$SOURCE" --apply --backup="$BACKUP" 2>&1); CODE=$?
check "Sprzątanie importera" 0 "$CODE"
printf '%s\n' "$OUT" | grep -E '^Razem'
OUT=$(bash "$WP" acorn fmi:import:cleanup 2>&1)
grep -q 'Razem: 0' <<< "$OUT"; ok "Po sprzątaniu nic nie zostało" $?

rm -f "$JAR" "$CJAR" "$HDR" "$B"
rmdir "$WORK" 2>/dev/null
echo "ETAP 14 — E2E: $PASS/$((PASS+FAIL)) PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
