#!/usr/bin/env bash
# Etap 08 — testy HTTP autozapisu, konfliktów, historii i postępu na działającym LocalWP.
# Wymaga manifestu z tests/stage03/setup.php i zasiewu tests/stage08/seed.php. Tokeny nie trafiają na wyjście.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-stage08-ids.json
[ -f "$MANIFEST" ] && [ -f "$IDS" ] || { echo "BLOCKED: brak danych testowych."; exit 2; }

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }
f() { json "$IDS" "fields.$1"; }

SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b); PAGE_A=$(json "$MANIFEST" pages.a); PAGE_B=$(json "$MANIFEST" pages.b)
C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
TOKEN_A=$(json "$IDS" tokens.a)
ROW_A=$(json "$IDS" rows.a); ROW_S=$(json "$IDS" rows.second)
REV1=$(json "$IDS" revisions.first); REV2=$(json "$IDS" revisions.second); REV_F=$(json "$IDS" revisions.foreign)
EDITOR="$SITE/p/$TOKEN_A"
CONTENT="$SITE/app/projects/$PA/pages/$PAGE_A/content"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -q -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -q -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono niedozwolony fragment"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }
hasntf() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — znaleziono sekret"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); SJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); OJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); MJAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX"); B=$(mktemp "${TMPDIR:-/tmp}/fmi-hdr.XXXXXX")
trap 'rm -f "$JAR" "$SJAR" "$OJAR" "$MJAR" "$B"' EXIT

meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$@"; }
body() { cat "$B"; }
jq_() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$B" "$1"; }

HTML=$(curl -s -c "$JAR" -b "$JAR" --max-time 25 "$EDITOR")
CSRF=$(meta "$HTML")
api() { local path="$1"; shift; curl -s -o "$B" -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CSRF" -X POST "$@" "$EDITOR/$path"; }

PANEL=$(curl -s -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$CONTENT")
SCSRF=$(meta "$PANEL")
sapi() { local path="$1"; shift; curl -s -o "$B" -w '%{http_code}' --max-time 25 -c "$SJAR" -b "$SJAR" -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $SCSRF" -X POST "$@" "$CONTENT/$path"; }

echo "== Edytor: postęp i status =="
has "Postęp projektu" "$HTML" "Cały projekt"
has "Postęp podstrony" "$HTML" "Ta podstrona"
has "Status globalny aria-live" "$HTML" 'role="status"'
has "Zakres szkiców" "$HTML" 'data-editor-scope='
hasnt "Klient bez linku historii" "$HTML" "?historia=$(f text)"
hasntf "HTML bez tokenu" "$HTML" "$TOKEN_A"

echo "== Konflikt (klient) =="
check "Pierwszy zapis" 200 "$(api "fields/$(f other)" -d 'base_version=0' --data-urlencode 'value=Pierwszy zapis')"
has "Postęp w odpowiedzi" "$(body)" '"progress"'
check "Drugi zapis z tą samą bazą → 409" 409 "$(api "fields/$(f other)" -d 'base_version=0' --data-urlencode 'value=Drugi zapis')"
check "Odpowiedź: moja wersja" "Drugi zapis" "$(jq_ mine.value)"
check "Odpowiedź: wersja zapisana" "Pierwszy zapis" "$(jq_ current.value)"
check "Odpowiedź: aktualna wersja" 1 "$(jq_ current.version)"
hasntf "Konflikt bez tokenu" "$(body)" "$TOKEN_A"
check "Ponowienie identycznego zapisu → 200" 200 "$(api "fields/$(f other)" -d 'base_version=0' --data-urlencode 'value=Pierwszy zapis')"
check "Konflikt linku: czytelny podgląd" 200 "$(api "fields/$(f link)" -d 'base_version=0' -d 'label=Pierwszy' --data-urlencode 'url=https://example.com/a')"
check "Konflikt linku → 409" 409 "$(api "fields/$(f link)" -d 'base_version=0' -d 'label=Drugi' --data-urlencode 'url=https://example.com/b')"
check "Podgląd linku" "Pierwszy https://example.com/a" "$(jq_ current.display)"

echo "== Konflikt (panel) =="
check "Panel: zapis z nieaktualną bazą → 409" 409 "$(sapi "fields/$(f other)" -d 'base_version=0' --data-urlencode 'value=Z panelu')"
check "Panel: wersja zapisana" "Pierwszy zapis" "$(jq_ current.value)"

echo "== Odrzucane zapisy =="
check "Klient: pole archiwalne → 403" 403 "$(api "fields/$(f archived)" -d 'base_version=0&value=x')"
check "Panel: pole archiwalne → 403" 403 "$(sapi "fields/$(f archived)" -d 'base_version=0&value=x')"
check "Klient: pole ukryte → 403" 403 "$(api "fields/$(f hidden)" -d 'base_version=0&value=x')"
check "Panel: pole ukryte → 200" 200 "$(sapi "fields/$(f hidden)" -d 'base_version=0' --data-urlencode 'value=Od zespołu')"
check "Wiersz innego pola → 404" 404 "$(api "fields/$(f row_field)" -d "base_version=0&value=x&row=$ROW_S")"
check "Panel: wiersz innego pola → 404" 404 "$(sapi "fields/$(f row_field)" -d "base_version=0&value=x&row=$ROW_S")"
check "Pole innego projektu → 404" 404 "$(api "fields/$(f foreign)" -d 'base_version=1&value=x')"
check "Panel: pole innego projektu → 404" 404 "$(sapi "fields/$(f foreign)" -d 'base_version=1&value=x')"
check "Drugi wiersz" 200 "$(api "fields/$(f repeater)/rows" -d 'base_version=1')"
check "Ponad max wierszy → 422" 422 "$(api "fields/$(f repeater)/rows" -d 'base_version=2')"
check "Panel: ponad max wierszy → 422" 422 "$(sapi "fields/$(f repeater)/rows" -d 'base_version=2')"

echo "== Historia =="
HIST=$(curl -s -b "$JAR" --max-time 25 "$EDITOR?historia=$(f text)")
has "Klient: historia" "$HIST" "Wersja 2"
has "Klient: starsza wersja" "$HIST" "Pierwsza wersja"
has "Klient: autor bez nazwiska" "$HIST" "Zespół OhSoFresh"
hasntf "Historia bez tokenu" "$HIST" "$TOKEN_A"
check "Klient: historia pola ukrytego → 403" 403 "$(code -b "$JAR" "$EDITOR?historia=$(f hidden)")"
check "Klient: historia pola archiwalnego → 403" 403 "$(code -b "$JAR" "$EDITOR?historia=$(f archived)")"
check "Klient: historia pola innego projektu → 404" 404 "$(code -b "$JAR" "$EDITOR?historia=$(f foreign)")"
check "Klient: historia repeatera → 404" 404 "$(code -b "$JAR" "$EDITOR?historia=$(f repeater)")"
check "Klient: historia pola listy bez wiersza → 404" 404 "$(code -b "$JAR" "$EDITOR?historia=$(f row_field)")"
check "Klient: historia wiersza" 200 "$(code -b "$JAR" "$EDITOR?historia=$(f row_field)&wiersz=$ROW_A")"
SHIST=$(curl -s -b "$SJAR" -H "Cookie: $C_ADMIN" --max-time 25 "$CONTENT/fields/$(f text)/history")
has "Panel: historia" "$SHIST" "Wersja 2"
hasnt "Panel: nazwisko zamiast zespołu" "$SHIST" "Zespół OhSoFresh"
check "Panel: historia pola innego projektu → 404" 404 "$(code -H "Cookie: $C_ADMIN" "$CONTENT/fields/$(f foreign)/history")"
check "Panel: pracownik bez przypisania → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$CONTENT/fields/$(f text)/history")"

echo "== Przywracanie =="
check "Bez CSRF → 419" 419 "$(code -X POST -H 'Accept: application/json' -d 'base_version=2' "$EDITOR/fields/$(f text)/revisions/$REV1/restore")"
check "Panel bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -d 'base_version=2' "$CONTENT/fields/$(f text)/revisions/$REV1/restore")"
check "Rewizja innego projektu → 404" 404 "$(api "fields/$(f text)/revisions/$REV_F/restore" -d 'base_version=2')"
check "Rewizja na polu innego projektu → 404" 404 "$(api "fields/$(f foreign)/revisions/$REV_F/restore" -d 'base_version=1')"
check "Nieaktualna baza → 409" 409 "$(api "fields/$(f text)/revisions/$REV1/restore" -d 'base_version=1')"
check "Przywrócenie" 200 "$(api "fields/$(f text)/revisions/$REV1/restore" -d 'base_version=2')"
check "Nowa wersja" 3 "$(jq_ version)"
check "Panel: nieaktualna baza → 409" 409 "$(sapi "fields/$(f text)/revisions/$REV2/restore" -d 'base_version=2')"

echo "== Autozapis wielu zapisów jednego pola =="
V=0
for i in 1 2 3; do
  check "Zapis $i" 200 "$(api "fields/$(f wysiwyg)" -d "base_version=$V" --data-urlencode "value=<p>Autozapis $i</p>")"
  V=$i
done

echo "== Uprawnienia panelu =="
check "Administrator: edytor" 200 "$(code -H "Cookie: $C_ADMIN" "$CONTENT")"
check "Przypisany pracownik: edytor" 200 "$(code -H "Cookie: $C_MEMBER" "$CONTENT")"
check "Pracownik bez przypisania: edytor → 404" 404 "$(code -H "Cookie: $C_OUTSIDER" "$CONTENT")"
check "Podstrona innego projektu → 404" 404 "$(code -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_B/content")"
check "Bez logowania → przekierowanie" 302 "$(code "$CONTENT")"
has "Panel: pole ukryte widoczne" "$PANEL" "Ukryte przed klientem"
has "Panel: adres zapisu" "$PANEL" "data-editor-base="
check "Panel: zapis bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -d 'base_version=0&value=x' "$CONTENT/fields/$(f other)")"
check "Panel: wiersz bez CSRF → 419" 419 "$(code -X POST -H "Cookie: $C_ADMIN" -H 'Accept: application/json' -d 'base_version=0' "$CONTENT/fields/$(f repeater)/rows")"
OCSRF=$(meta "$(curl -s -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" --max-time 25 "$SITE/app/projects")")
check "Pracownik bez przypisania: zapis → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $OCSRF" -X POST -d 'base_version=1&value=x' "$CONTENT/fields/$(f other)")"
check "Pracownik bez przypisania: przywrócenie → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -c "$OJAR" -b "$OJAR" -H "Cookie: $C_OUTSIDER" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $OCSRF" -X POST -d 'base_version=3' "$CONTENT/fields/$(f text)/revisions/$REV2/restore")"

echo
echo "HTTP etapu 08: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
