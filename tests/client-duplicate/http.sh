#!/usr/bin/env bash
# Klient: „Dodaj kolejną taką podstronę” i usunięcie własnej podstrony w edytorze /p/{token}.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"; fmi_bootstrap >/dev/null 2>&1
MANIFEST=/tmp/fmi-stage03-fixtures.json; IDS=/tmp/fmi-stage07-ids.json
json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo is_array($m)?json_encode($m):$m;' "$1" "$2"; }
SITE=$(json "$MANIFEST" site); PAGE=$(json "$MANIFEST" pages.a); PAGE_B=$(json "$MANIFEST" pages.b); CHILD=$(json "$IDS" pages.child)
EDITOR="$SITE/p/$(json "$IDS" tokens.a)"; EDITOR_B="$SITE/p/$(json "$IDS" tokens.b)"

PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
has() { if grep -qF -- "$3" <<< "$2"; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1 — brak: $3"; FAIL=$((FAIL+1)); fi; }
hasnt() { if grep -qF -- "$3" <<< "$2"; then echo "  FAIL  $1 — jest: $3"; FAIL=$((FAIL+1)); else echo "  PASS  $1"; PASS=$((PASS+1)); fi; }

WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmi-cdup.XXXXXX"); chmod 700 "$WORK"; trap 'rm -rf "$WORK"' EXIT
JAR="$WORK/jar"; JB="$WORK/jarb"
get() { curl -s --max-time 30 -c "$JAR" -b "$JAR" "$@"; }
meta() { printf '%s' "$1" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//'; }
post() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 30 -c "$JAR" -b "$JAR" -H "X-CSRF-TOKEN: $CSRF" -X POST "$@"; }
state() { fmiwp eval-file "$THEME/tests/client-duplicate/state.php" state "$PAGE" 2>/dev/null; }
q() { php -r "\$s=json_decode(\$argv[1],true); $1" "$(state)"; }

echo "== Bez zgody zespołu =="
HTML=$(get "$EDITOR?strona=$PAGE"); CSRF=$(meta "$HTML")
hasnt "Brak przycisku bez client_can_duplicate" "$HTML" 'data-client-duplicate'
R=$(post "$EDITOR/pages/$PAGE/duplicate" -d 'name=Nie+wolno')
check "Duplikowanie bez zgody → powrót z błędem" 302 "${R%% *}"
check "Nic nie dodano" 0 "$(q 'echo count(array_filter($s, fn($p) => $p["from"]));')"

echo "== Ze zgodą =="
fmiwp eval-file "$THEME/tests/client-duplicate/state.php" allow "$PAGE" 2>/dev/null
HTML=$(get "$EDITOR?strona=$PAGE"); CSRF=$(meta "$HTML")
has "Przycisk „Dodaj kolejną taką podstronę”" "$HTML" 'Dodaj kolejną taką podstronę'
hasnt "Oryginału klient nie usuwa" "$HTML" 'data-client-remove'
hasnt "Token nie trafia do HTML akcji" "$HTML" "$(json "$IDS" tokens.a)/pages"
check "Bez nazwy → walidacja (302 z błędem)" 302 "$(post "$EDITOR/pages/$PAGE/duplicate" -d 'name=' | cut -d' ' -f1)"
check "Bez CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'name=X' "$EDITOR/pages/$PAGE/duplicate")"
R=$(post "$EDITOR/pages/$PAGE/duplicate" -d 'name=Płyta+DK-NG+Grafit')
check "Dodanie → przekierowanie" 302 "${R%% *}"
NEW=$(q 'foreach ($s as $p) if ($p["from"] && ! $p["archived"]) echo $p["id"];')
has "Przekierowanie na nową podstronę" "${R#* }" "strona=$NEW"
check "Nazwa kopii" 'Płyta DK-NG Grafit' "$(q 'foreach ($s as $p) if ($p["id"] == '"${NEW:-0}"') echo $p["name"];')"
check "Te same sekcje i pola" "$(q 'foreach ($s as $p) if ($p["id"] == '"$PAGE"') echo $p["sections"], "/", $p["fields"];')" "$(q 'foreach ($s as $p) if ($p["id"] == '"${NEW:-0}"') echo $p["sections"], "/", $p["fields"];')"
check "Pusta treść" 0 "$(q 'foreach ($s as $p) if ($p["id"] == '"${NEW:-0}"') echo $p["values"];')"
HTML=$(get "$EDITOR?strona=$NEW")
has "Kopia w menu podstron" "$HTML" "?strona=$NEW"
has "Komunikat o dodaniu" "$HTML" 'Dodano podstronę'
has "Własną podstronę klient może usunąć" "$HTML" 'data-client-remove'

echo "== Granice dostępu =="
HB=$(curl -s -c "$JB" -b "$JB" "$EDITOR_B"); CB=$(meta "$HB")
check "Link innego projektu: duplikat → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -c "$JB" -b "$JB" -H "X-CSRF-TOKEN: $CB" -X POST -d 'name=X' "$EDITOR_B/pages/$PAGE/duplicate")"
check "Link innego projektu: usunięcie → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -c "$JB" -b "$JB" -H "X-CSRF-TOKEN: $CB" -X POST "$EDITOR_B/pages/$NEW/archive")"
check "Podstrona innego projektu → 404" 404 "$(post "$EDITOR/pages/$PAGE_B/duplicate" -d 'name=X' | cut -d' ' -f1)"
check "Usunięcie oryginału → odmowa (302)" 302 "$(post "$EDITOR/pages/$PAGE/archive" | cut -d' ' -f1)"
check "Oryginał nadal aktywny" '' "$(q 'foreach ($s as $p) if ($p["id"] == '"$PAGE"' && $p["archived"]) echo "tak";')"
check "Cudzej (pracownika) podstrony nie usunie" 302 "$(post "$EDITOR/pages/$CHILD/archive" | cut -d' ' -f1)"

echo "== Usunięcie własnej =="
R=$(post "$EDITOR/pages/$NEW/archive")
check "Usunięcie → przekierowanie" 302 "${R%% *}"
has "Powrót na oryginał" "${R#* }" "strona=$PAGE"
check "Kopia zarchiwizowana (do przywrócenia)" 'tak' "$(q 'foreach ($s as $p) if ($p["id"] == '"${NEW:-0}"' && $p["archived"]) echo "tak";')"
check "Zarchiwizowana kopia → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$EDITOR?strona=$NEW")"

echo
echo "KLIENT — duplikowanie podstron: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
