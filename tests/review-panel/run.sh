#!/usr/bin/env bash
#
# Design Review, etap 04 — panel: lista recenzji (zakres, liczniki, statusy, aktywność), strona recenzji,
# zapis i walidacja linku prototypu Figmy, odmowy, CSRF, brak sieci. Dane zawsze sprzątane.
#
# Użycie: bash tests/review-panel/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmirp.XXXXXX")
chmod 700 "$WORK"
B="$WORK/b"; H="$WORK/h"; JAR="$WORK/jar"; S="$WORK/state.json"

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-panel/fixtures.php" setup || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_bool($m)?($m?"1":"0"):(is_scalar($m)?$m:json_encode($m));' "$1" "$2"; }
SITE=$(json "$MANIFEST" site); SUFFIX=$(json "$MANIFEST" suffix)
PA=$(json "$MANIFEST" projects.a); PR=$(json "$MANIFEST" projects.r); PD=$(json "$MANIFEST" projects.d)
ADMIN=$(json "$MANIFEST" cookies.admin); MANAGER=$(json "$MANIFEST" cookies.manager)
MEMBER=$(json "$MANIFEST" cookies.member); OUTSIDER=$(json "$MANIFEST" cookies.outsider)
PASS=0; FAIL=0

get() { curl -s -o "$B" -D "$H" -w '%{http_code}' --max-time 30 -b "$JAR" -c "$JAR" -H "Cookie: $1" "$SITE$2"; }
post() { local c="$1" path="$2"; shift 2; curl -s -o "$B" -D "$H" -w '%{http_code}' --max-time 30 -b "$JAR" -c "$JAR" -H "Cookie: $c" -X POST "$@" "$SITE$path"; }
csrf() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -qF -- "$1" "$B"; }
state() { fmiwp eval-file "$THEME/tests/review-panel/fixtures.php" state "$S" 2>/dev/null; }
# Wiersz listy projektu (od data-review-row do końca <li>) → pole: open | approval | time
row() { php -r '$h=file_get_contents($argv[1]); if(!preg_match("~data-review-row=\"".$argv[2]."\".*?</li>~s",$h,$m)){echo "BRAK";exit;} $r=$m[0];
  if($argv[3]==="open"){preg_match("~data-open-threads[^>]*>(\d+)<~",$r,$x);echo $x[1]??"?";}
  elseif($argv[3]==="time"){preg_match("~<time[^>]*>([^<]*)</time>~",$r,$x);echo trim($x[1]??"?");}
  else{preg_match("~data-approval>(.*?)</div>~s",$r,$x);$t=preg_replace("~\s+~u"," ",html_entity_decode(strip_tags($x[1]??"?")));echo trim(str_replace("Status akceptacji:","",$t));}' "$B" "$1" "$2"; }

state
echo "== Zakres i zbiory =="
get "$OUTSIDER" /app/design-review >/dev/null
has 'Brak projektów w Design Review'; ok "Pusty zbiór (pracownik bez projektów): pusty stan" $?
check "DB: pracownik spoza widzi 0" 0 "$(json "$S" visible.outsider)"

check "Członek: lista" 200 "$(get "$MEMBER" /app/design-review)"
grep -c 'data-review-row=' "$B" | { read -r n; check "Członek: liczba wierszy = DB" "$(json "$S" visible.member)" "$n"; }
grep -q "data-review-row=\"$PR\"" "$B" && grep -q "data-review-row=\"$PD\"" "$B"; ok "Członek: R i D (mieszany zbiór)" $?
! grep -q "data-review-row=\"$PA\"" "$B"; ok "Członek: bez A (projekt bez Design Review)" $?
! has "duży zbiór"; ok "Członek: bez projektów, do których nie jest przypisany" $?
check "R: otwarte uwagi = DB" "$(json "$S" r.open)" "$(row "$PR" open)"
check "D: otwarte uwagi = DB" "$(json "$S" d.open)" "$(row "$PD" open)"
check "R: status akceptacji" "$(json "$S" r.latest_status) · Rewizja $(json "$S" r.latest_number)" "$(row "$PR" approval)"
check "D: brak rewizji" "Brak rewizji" "$(row "$PD" approval)"
check "R: ostatnia aktywność = DB" "$(json "$S" r.last_activity)" "$(row "$PR" time)"

check "Opiekun: duży zbiór, strona 1" 200 "$(get "$MANAGER" /app/design-review)"
TOTAL=$(json "$S" visible.manager)
has "· $TOTAL pozycji"; ok "Opiekun: paginacja pokazuje $TOTAL pozycji (DB)" $?
check "Opiekun: 20 wierszy na stronie" 20 "$(grep -c 'data-review-row=' "$B")"
LAST=$(( (TOTAL + 19) / 20 ))
check "Opiekun: ostatnia strona $LAST" 200 "$(get "$MANAGER" "/app/design-review?page=$LAST")"
check "Opiekun: wiersze na ostatniej stronie" $(( TOTAL - (LAST - 1) * 20 )) "$(grep -c 'data-review-row=' "$B")"
check "Strona poza zakresem → ostatnia" 200 "$(get "$MANAGER" "/app/design-review?page=999")"
check "Admin: lista" 200 "$(get "$ADMIN" /app/design-review)"
check "Admin: liczba pozycji = DB" 1 "$(grep -c "· $(json "$S" visible.admin) pozycji" "$B")"

for p in 1 2 3; do get "$MANAGER" "/app/design-review?page=$p" >/dev/null; has "TEST długa nazwa $SUFFIX" && break; done
has 'break-words'; ok "Długa nazwa (190 znaków) renderowana z zawijaniem" $?
for p in 1 2 3; do get "$MANAGER" "/app/design-review?page=$p" >/dev/null; has "&lt;script&gt;alert(1)&lt;/script&gt; $SUFFIX" && break; done
has "&lt;script&gt;alert(1)&lt;/script&gt; $SUFFIX"; ok "HTML w nazwie escapowany" $?
! has "<script>alert(1)</script>"; ok "Brak surowego <script>" $?

echo "== Zmiany statusów wątków =="
fmiwp eval-file "$THEME/tests/review-panel/fixtures.php" thread resolve r; state
get "$MEMBER" /app/design-review >/dev/null
check "Po rozwiązaniu wątku: licznik = DB ($(json "$S" r.open))" "$(json "$S" r.open)" "$(row "$PR" open)"
fmiwp eval-file "$THEME/tests/review-panel/fixtures.php" thread open r; fmiwp eval-file "$THEME/tests/review-panel/fixtures.php" thread open r; state
get "$MEMBER" /app/design-review >/dev/null
check "Po ponownym otwarciu 2 wątków: licznik = DB ($(json "$S" r.open))" "$(json "$S" r.open)" "$(row "$PR" open)"
check "Recenzja R: licznik = DB" 200 "$(get "$MEMBER" "/app/design-review/$PR")"
grep -q "data-open-threads>$(json "$S" r.open)<" "$B"; ok "Recenzja R: otwarte uwagi $(json "$S" r.open)" $?

echo "== Strona recenzji i uprawnienia =="
has 'Tylko podgląd'; ok "Członek (bez fmi_manage_reviews): tylko podgląd, bez formularza" $?
! has 'name="figma_url"'; ok "Członek: brak pola zapisu" $?
check "Spoza projektu: recenzja R → 404" 404 "$(get "$OUTSIDER" "/app/design-review/$PR")"
check "Recenzja A (bez Design Review) → 404, admin" 404 "$(get "$ADMIN" "/app/design-review/$PA")"
check "Nieistniejący projekt → 404" 404 "$(get "$ADMIN" "/app/design-review/999999999")"
check "Gość → logowanie" 302 "$(get "" "/app/design-review/$PR")"

check "Opiekun: recenzja D" 200 "$(get "$MANAGER" "/app/design-review/$PD")"
has 'data-loading-form'; ok "Formularz ze stanem ładowania" $?
has 'data-subpages-form' && has 'Brak podstron'; ok "Pusty stan: formularz Podstrony (nazwa → link)" $?
CSRF=$(csrf)

echo "== Walidacja linku =="
bad() {
  check "Odrzucony: $1" 302 "$(post "$MANAGER" "/app/design-review/$PD/prototype" -H "X-CSRF-TOKEN: $CSRF" --data-urlencode "figma_url=$2")"
  get "$MANAGER" "/app/design-review/$PD" >/dev/null
  has "$3"; ok "Komunikat: $3" $?
  CSRF=$(csrf)
}
bad "pusty" "" "Wklej link do prototypu Figmy."
bad "http zamiast https" "http://www.figma.com/proto/abcdefghij12/x" "Link musi prowadzić do Figmy przez https"
bad "obca domena" "https://www.figma.com.evil.example/proto/abcdefghij12/x" "Link musi prowadzić do Figmy przez https"
bad "javascript:" "javascript:alert(1)" "Link musi prowadzić do Figmy przez https"
bad "dane logowania w adresie" "https://user:pass@www.figma.com/proto/abcdefghij12/x" "To nie jest link do prototypu ani projektu Figmy"
bad "port" "https://www.figma.com:8443/proto/abcdefghij12/x" "To nie jest link do prototypu ani projektu Figmy"
bad "FigJam (board)" "https://www.figma.com/board/abcdefghij12/x" "To nie jest link do prototypu ani projektu Figmy"
bad "zły node-id" "https://www.figma.com/proto/abcdefghij12/x?node-id=abc" "Parametr node-id w linku jest nieprawidłowy"
bad "za długi" "https://www.figma.com/proto/abcdefghij12/$(printf 'a%.0s' $(seq 1 520))" "Link jest za długi"
state
check "Po błędach: brak rewizji D" 0 "$(json "$S" d.revisions)"

echo "== Zapis =="
check "Bez CSRF → 419" 419 "$(post "$MANAGER" "/app/design-review/$PD/prototype" --data-urlencode 'figma_url=https://www.figma.com/proto/abcdefghij12/x')"
check "Członek: zapis → 403" 403 "$(post "$MEMBER" "/app/design-review/$PD/prototype" -H "X-CSRF-TOKEN: $CSRF" --data-urlencode 'figma_url=https://www.figma.com/proto/abcdefghij12/x')"
check "Spoza projektu: zapis → 404" 404 "$(post "$OUTSIDER" "/app/design-review/$PD/prototype" -H "X-CSRF-TOKEN: $CSRF" --data-urlencode 'figma_url=https://www.figma.com/proto/abcdefghij12/x')"
check "Projekt bez Design Review (A): zapis → 404" 404 "$(post "$ADMIN" "/app/design-review/$PA/prototype" -H "X-CSRF-TOKEN: $CSRF" --data-urlencode 'figma_url=https://www.figma.com/proto/abcdefghij12/x')"
state
check "Po odmowach: brak rewizji D" 0 "$(json "$S" d.revisions)"
URL1='https://www.figma.com/proto/abcdefghij12/Projekt?node-id=1-2&starting-point-node-id=1%3A2'
check "Opiekun: poprawny link" 302 "$(post "$MANAGER" "/app/design-review/$PD/prototype" -H "X-CSRF-TOKEN: $CSRF" --data-urlencode "figma_url=$URL1")"
state
check "D: rewizja 1" 1 "$(json "$S" d.latest_number)"
check "D: status oczekuje na decyzję (draft)" "Oczekuje na decyzję" "$(json "$S" d.latest_status)"
check "D: zapisany link" "$URL1" "$(json "$S" d.latest_url)"
get "$MANAGER" "/app/design-review/$PD" >/dev/null
has 'Zapisano link prototypu jako rewizja 1.'; ok "Komunikat zapisu" $?
has 'rel="noopener noreferrer"' && has 'referrerpolicy="no-referrer"'; ok "Link do Figmy bez Referer i opener" $?
get "$MEMBER" /app/design-review >/dev/null
check "Lista: D status po zapisie" "Oczekuje na decyzję · Rewizja 1" "$(row "$PD" approval)"
check "Lista: D ostatnia aktywność = DB" "$(json "$S" d.last_activity)" "$(row "$PD" time)"
get "$MANAGER" "/app/design-review/$PR" >/dev/null; CSRF=$(csrf)
check "R (rewizja zaakceptowana): nowy link" 302 "$(post "$MANAGER" "/app/design-review/$PR/prototype" -H "X-CSRF-TOKEN: $CSRF" --data-urlencode 'figma_url=https://www.figma.com/design/abcdefghij12/R2')"
state
check "R: nowa rewizja 2 (nowa decyzja)" 2 "$(json "$S" r.latest_number)"
check "R: rewizje 2" 2 "$(json "$S" r.revisions)"

echo "== Brak sieci =="
fmiwp eval-file "$THEME/tests/review-panel/network.php"; ok "Zapis bez sieci (serwis)" $?

echo
echo "PANEL DESIGN REVIEW: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
