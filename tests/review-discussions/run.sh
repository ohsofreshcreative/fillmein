#!/usr/bin/env bash
#
# Design Review, etap 08 — dyskusje i statusy: serwis (cykl, historia, konflikty, idempotencja, uprawnienia),
# HTTP (formularze, filtry i liczniki, dwie sesje równolegle, ponowienie po timeoucie, odmowy, XSS, CSRF, limit),
# przeglądarka headless (odpowiedź, statusy, filtry, historia, klawiatura, axe, 375 px). Dane zawsze sprzątane.
#
# Użycie: bash tests/review-discussions/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-review-discussions.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmird.XXXXXX"); chmod 700 "$WORK"
B="$WORK/b"

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f "$IDS"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-discussions/fixtures.php" || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$1" "$2"; }
SITE=$(json "$MANIFEST" site)
MANAGER=$(json "$MANIFEST" cookies.manager); MEMBER=$(json "$MANIFEST" cookies.member); OUTSIDER=$(json "$MANIFEST" cookies.outsider)
D=$(json "$IDS" d); T1=$(json "$IDS" t1); T2=$(json "$IDS" t2); T3=$(json "$IDS" t3); TE=$(json "$IDS" te); C1=$(json "$IDS" c1); C2=$(json "$IDS" c2)
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
state() { fmiwp eval-file "$THEME/tests/review-discussions/state.php" "$1" "$2" 2>/dev/null | tail -1; }
uuid() { uuidgen | tr 'A-Z' 'a-z'; }
# Każda „sesja” ma własny słoik ciasteczek (osobna sesja Laravela i token CSRF).
get() { curl -s -o "$B" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$1" -c "$WORK/jar-$1" -H "Cookie: $2" "$SITE$3"; }
token() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
form() { local s="$1" c="$2" t="$3" path="$4"; shift 4; curl -s -o "$WORK/out-$s" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "Cookie: $c" -H "X-CSRF-TOKEN: $t" "$@" "$SITE$path"; }
jpost() { form "$@" -H 'Accept: application/json'; }
count() { perl -0ne 'print $1 if /data-filter="'"$1"'".*?data-filter-count>(\d+)/s' "$B"; }
listed() { grep -o 'data-thread="[0-9]*"' "$B" | grep -o '[0-9]*' | tr '\n' ',' | sed 's/,$//'; }

echo "== Serwis =="
fmiwp eval-file "$THEME/tests/review-discussions/service.php"; ok "Reguły serwisu i uprawnienia" $?

P="/app/design-review/$D"
get m "$MANAGER" "$P" >/dev/null; TM=$(token)
get u "$MEMBER" "$P" >/dev/null; TU=$(token)

echo "== Pełny cykl statusów i filtry (formularze, opiekun) =="
get m "$MANAGER" "$P" >/dev/null
check "Liczniki na start: wszystkie / nowe / w trakcie / rozwiązane" "3/3/0/0" "$(count all)/$(count nowe)/$(count w-trakcie)/$(count rozwiazane)"
check "Nowy → W trakcie" 302 "$(form m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=in_progress -d version=0 -d filter=nowe)"
LOC=$(curl -s -o /dev/null -D - -b "$WORK/jar-m" -c "$WORK/jar-m" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" -d status=in_progress -d version=1 -d filter=nowe "$SITE$P/threads/$T1/status" | grep -i '^location:' | tr -d '\r')
echo "$LOC" | grep -q 'status=nowe#uwaga-1'; ok "Powrót do wątku z zachowanym filtrem (ponowienie: bez zmian)" $?
check "Stan po W trakcie: status|wersja|wiadomości|historia" "in_progress|1|1|1" "$(state thread "$T1")"
get m "$MANAGER" "$P?status=w-trakcie" >/dev/null
check "Filtr W trakcie: tylko uwaga 1" "1" "$(listed)"
check "Liczniki: 3/2/1/0" "3/2/1/0" "$(count all)/$(count nowe)/$(count w-trakcie)/$(count rozwiazane)"
grep -q 'data-filter="w-trakcie"[^>]*aria-current="page"\|aria-current="page"[^>]*data-filter="w-trakcie"' "$B"; ok "Aktywny filtr oznaczony aria-current" $?
check "W trakcie → Rozwiązany" 302 "$(form m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=resolved -d version=1)"
get m "$MANAGER" "$P?status=rozwiazane" >/dev/null
check "Filtr Rozwiązane: uwaga 1" "1" "$(listed)"
grep -q 'data-thread-closed' "$B" && ! grep -q "threads/$T1/replies" "$B"; ok "Rozwiązana: bez formularza odpowiedzi" $?
get m "$MANAGER" "$P?status=nowe" >/dev/null
check "Filtr Nowe: uwagi 2 i 3" "2,3" "$(listed)"
check "Ponowne otwarcie (Rozwiązany → Nowy)" 302 "$(form m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=open -d version=2)"
check "Stan po ponownym otwarciu" "open|3|1|3" "$(state thread "$T1")"
get m "$MANAGER" "$P" >/dev/null
grep -q 'data-event="reopened"' "$B" && grep -q 'ponownie otworzył(a) wątek (Rozwiązany → Nowy)' "$B"; ok "Historia na stronie: ponowne otwarcie z autorem" $?
check "Liczniki po cyklu: 3/3/0/0" "3/3/0/0" "$(count all)/$(count nowe)/$(count w-trakcie)/$(count rozwiazane)"
get m "$MANAGER" "$P?status=bzdura" >/dev/null
check "Nieznany filtr → wszystkie uwagi" "1,2,3" "$(listed)"

echo "== Konflikt i ponowienie statusu =="
check "Nieaktualna wersja, inny status → 409" 409 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=resolved -d version=0)"
grep -q '"status":"open"' "$WORK/out-m"; ok "Konflikt zwraca bieżący stan" $?
check "Ponowienie zmiany, która już obowiązuje → 200 bez wpisu" 200 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=open -d version=2)"
check "Historia bez dodatkowego wpisu" "open|3|1|3" "$(state thread "$T1")"
check "Konflikt przez formularz → 302 z komunikatem" 302 "$(form m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=in_progress -d version=0)"
get m "$MANAGER" "$P" >/dev/null
grep -q 'zmienił się w międzyczasie' "$B"; ok "Komunikat konfliktu pod wątkiem" $?
check "Nieznany status → 422" 422 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T1/status" -d status=closed -d version=3)"

echo "== Uprawnienia do statusu i edycji =="
check "Pracownik: W trakcie na własnej uwadze → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T2/status" -d status=in_progress -d version=0)"
check "Pracownik: Rozwiąż cudzą → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T1/status" -d status=resolved -d version=3)"
check "Stan uwagi 2 bez zmian" "open|0|1|0" "$(state thread "$T2")"
check "Opiekun rozwiązuje uwagę 2" 200 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T2/status" -d status=resolved -d version=0)"
check "Opiekun rozwiązuje uwagę 3" 200 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T3/status" -d status=resolved -d version=0)"
check "Pracownik: ponowne otwarcie cudzej → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T3/status" -d status=open -d version=1)"
check "Autor-pracownik: ponowne otwarcie własnej → 200" 200 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T2/status" -d status=open -d version=1)"
get u "$MEMBER" "$P" >/dev/null
! grep -q 'data-status-target="in_progress"' "$B"; ok "Pracownik: brak przycisków zmiany statusu" $?
check "Pracownik edytuje wiadomość opiekuna → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T1/comments/$C1" -d body=Zmiana -d version=0)"
check "Opiekun edytuje wiadomość pracownika → 403" 403 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T2/comments/$C2" -d body=Zmiana -d version=0)"
check "Wiadomość z innego wątku w adresie → 404" 404 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T1/comments/$C2" -d body=Zmiana -d version=0)"
check "Pracownik edytuje własną (formularz)" 302 "$(form u "$MEMBER" "$TU" "$P/threads/$T2/comments/$C2" --data-urlencode 'body=Uwaga członka <b>v2</b>' -d version=0)"
check "Edycja ze starą wersją (druga karta) → 409" 409 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T2/comments/$C2" -d body=Inna -d version=0)"
get u "$MEMBER" "$P" >/dev/null
grep -qF 'Uwaga członka &lt;b&gt;v2&lt;/b&gt;' "$B" && grep -qF 'Poprzednio: Uwaga członka' "$B" && grep -q 'data-comment-edited' "$B"; ok "Edycja: nowa treść escapowana, poprzednia w historii, znacznik „edytowano”" $?
get o "$OUTSIDER" /app/design-review >/dev/null; TO=$(token)
check "Spoza projektu: status → 404" 404 "$(jpost o "$OUTSIDER" "$TO" "$P/threads/$T1/status" -d status=resolved -d version=3)"
check "Spoza projektu: odpowiedź → 404" 404 "$(jpost o "$OUTSIDER" "$TO" "$P/threads/$T1/replies" -d body=x -d request_id="$(uuid)")"
check "Wątek obcego projektu przez adres D → 404" 404 "$(jpost m "$MANAGER" "$TM" "$P/threads/$TE/replies" -d body=x -d request_id="$(uuid)")"
check "Bez CSRF → 419" 419 "$(jpost m "$MANAGER" "" "$P/threads/$T1/replies" -d body=x -d request_id="$(uuid)")"

echo "== Odpowiedzi: dwie sesje, ponowienia, timeout =="
form m "$MANAGER" "$TM" "$P/threads/$T1/replies" -H 'Accept: application/json' -d 'body=Sesja opiekuna' -d request_id="$(uuid)" > "$WORK/p1" &
form u "$MEMBER" "$TU" "$P/threads/$T1/replies" -H 'Accept: application/json' -d 'body=Sesja pracownika' -d request_id="$(uuid)" > "$WORK/p2" &
wait
check "Dwie sesje równolegle → 201 i 201" "201 201" "$(cat "$WORK/p1") $(cat "$WORK/p2")"
check "Obie odpowiedzi zapisane" "open|3|3|3" "$(state thread "$T1")"
RID=$(uuid)
for i in 1 2 3 4 5; do curl -s -o /dev/null -w '%{http_code}' --max-time 60 -b "$WORK/jar-m" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" -H 'Accept: application/json' -d 'body=Podwójne kliknięcie' -d request_id="$RID" "$SITE$P/threads/$T1/replies" > "$WORK/r$i" & done
wait
check "5 równoległych ponowień z tym samym request_id → 1 wpis" 1 "$(state rid "$RID")"
check "Wszystkie ponowienia: 201" "201 201 201 201 201" "$(for i in 1 2 3 4 5; do printf '%s ' "$(cat "$WORK/r$i")"; done | sed 's/ $//')"
RID2=$(uuid)
curl -s -o /dev/null --max-time 0.01 -b "$WORK/jar-m" -c "$WORK/jar-m" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" -H 'Accept: application/json' -d 'body=Po timeoucie' -d request_id="$RID2" "$SITE$P/threads/$T1/replies"
echo "  INFO  timeout klienta: kod curl $? (28 = przerwane)"; sleep 3
FIRST=$(state rid "$RID2")
echo "  INFO  wpisów po przerwanym żądaniu: $FIRST (serwer $( [ "$FIRST" = 1 ] && echo 'dokończył' || echo 'nie dokończył' ) zapis)"
check "Ponowienie po timeoucie → 201" 201 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T1/replies" -d 'body=Po timeoucie' -d request_id="$RID2")"
check "Po timeoucie i ponowieniu: dokładnie 1 wpis" 1 "$(state rid "$RID2")"
get v "$MANAGER" "$P" >/dev/null; TV=$(token)
check "Pusta odpowiedź (formularz, osobna sesja) → 302" 302 "$(form v "$MANAGER" "$TV" "$P/threads/$T1/replies" -d 'body=  ' -d request_id="$(uuid)" -d thread_form="$T1")"
get v "$MANAGER" "$P" >/dev/null
# Bez `grep -q` w potoku: przy pipefail przerwany sed (SIGPIPE) dałby fałszywy błąd.
sed -n "/id=\"uwaga-1\"/,/id=\"uwaga-2\"/p" "$B" | grep 'Wpisz treść odpowiedzi' >/dev/null; R=$?; ok "Błąd walidacji pod właściwym wątkiem" $R
check "Odpowiedź w rozwiązanym wątku 3 → 422" 422 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T3/replies" -d body=x -d request_id="$(uuid)")"
check "Odpowiedź z XSS" 201 "$(jpost u "$MEMBER" "$TU" "$P/threads/$T2/replies" --data-urlencode 'body=<img src=x onerror=alert(1)>' -d request_id="$(uuid)")"
get u "$MEMBER" "$P" >/dev/null
grep -qF '&lt;img src=x onerror=alert(1)&gt;' "$B" && ! grep -q '<img src=x' "$B"; ok "Odpowiedź escapowana" $?
check "Odpowiedź bez request_id → 422" 422 "$(jpost m "$MANAGER" "$TM" "$P/threads/$T1/replies" -d body=x)"

echo "== Przeglądarka =="
node "$THEME/tests/review-discussions/ui.mjs"; ok "Dyskusje w przeglądarce" $?

echo "== Limit częstotliwości =="
LAST=""
for i in $(seq 1 25); do LAST=$(jpost m "$MANAGER" "$TM" "$P/threads/$T1/replies" -d "body=seria $i" -d request_id="$(uuid)"); [ "$LAST" = 429 ] && break; done
check "Seria odpowiedzi → 429" 429 "$LAST"

echo
echo "DYSKUSJE I STATUSY: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
