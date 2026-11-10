#!/usr/bin/env bash
#
# Design Review, etap 12 — scenariusz odbiorczy z QA.md, krok po kroku przez HTTP (pracownicy i gość):
# stary link Treści → prototyp → uwaga z pinezką → odpowiedź → statusy → link klienta → poprawki A → rewizja B →
# akceptacja B → rewizja C oczekuje → powiadomienia bez duplikatów, odebrany dostęp → unieważnienie linku → brak
# tokenów w logu → Treść i stary link bez zmian. Prototyp: plik niedostępny w Figmie (bez projektu klienta).
#
# Użycie: bash tests/review-acceptance/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-review-acceptance.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmira.XXXXXX"); chmod 700 "$WORK"
B="$WORK/b"
LARAVEL_LOG="$THEME/../../cache/acorn/logs/laravel.log"
LOG_OFFSET=$( [ -f "$LARAVEL_LOG" ] && wc -c < "$LARAVEL_LOG" | tr -d ' ' || echo 0)

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f "$IDS"; rm -rf "$WORK"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-acceptance/fixtures.php" || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$1" "$2"; }
jget() { php -r '$m=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?(is_bool($m)?($m?"true":"false"):$m):json_encode($m);' "$1"; }
SITE=$(json "$MANIFEST" site)
MANAGER=$(json "$MANIFEST" cookies.manager); MEMBER=$(json "$MANIFEST" cookies.member)
U_MEMBER=$(json "$MANIFEST" users.member); U_MANAGER=$(json "$MANIFEST" users.manager)
Q=$(json "$IDS" q); F=$(json "$IDS" f); FT=$(json "$IDS" ft); FIELD=$(json "$IDS" field); OLD=$(json "$IDS" old)
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
admin() { fmiwp eval-file "$THEME/tests/review-acceptance/admin.php" "$@" 2>/dev/null | tail -1; }
state() { admin state "$Q"; }
uuid() { uuidgen | tr 'A-Z' 'a-z'; }
token() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
# Pracownik (słoik + ciasteczka WP) i gość (tylko słoik).
get() { curl -s -o "$B" -D "$WORK/h" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$1" -c "$WORK/jar-$1" -H "Cookie: $2" "$SITE$3"; }
post() { local s="$1" c="$2" t="$3" path="$4"; shift 4; curl -s -o "$B" -D "$WORK/h" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "Cookie: $c" -H "X-CSRF-TOKEN: $t" -X POST "$@" "$SITE$path"; }
jpost() { post "$@" -H 'Accept: application/json'; }
gget() { curl -s -o "$B" -D "$WORK/h" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$1" -c "$WORK/jar-$1" "$SITE$2"; }
gpost() { local s="$1" t="$2" path="$3"; shift 3; curl -s -o "$B" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "X-CSRF-TOKEN: $t" -H 'Accept: application/json' -X POST "$@" "$SITE$path"; }
loc() { grep -i '^location:' "$WORK/h" | tr -d '\r' | sed 's/^[Ll]ocation: //'; }
dec() { echo -d "decision=$1" -d "revision=$2" -d "request_id=$(uuid)" -d confirm=1; }
P="/app/design-review/$Q"

echo "== 1. Stary link Treści: odczyt i zapis =="
check "Stary link /p/{token}: edytor" 200 "$(gget c "/p/$OLD")"; TC=$(token)
grep -q 'Istniejąca treść klienta' "$B"; ok "Istniejąca treść widoczna" $?
check "Zapis treści przez stary link" 200 "$(gpost c "$TC" "/p/$OLD/fields/$FIELD" -d base_version=1 -d value='Treść po zmianie klienta')"
check "Treść zapisana" "Treść po zmianie klienta" "$(admin value "$FIELD")"
check "Stary link: zakres Treści, aktywny" "content|active" "$(admin access "$(json "$IDS" old_access)")"
check "Stary link nie otwiera recenzji (/r)" 404 "$(gget c2 "/r/$OLD")"

echo "== 2. Design Review we wspólnym projekcie: prototyp, widok ekranów =="
get m "$MANAGER" "$P" >/dev/null; TM=$(token)
check "Zapis linku prototypu (opiekun)" 302 "$(post m "$MANAGER" "$TM" "$P/prototype" --data-urlencode 'figma_url=https://www.figma.com/proto/ZZtestNoAccess0000000000/Odbior?node-id=1-2')"
check "Rewizja 1 z prototypem" "ZZtestNoAccess0000000000" "$(state | jget prototype)"
check "Strona recenzji" 200 "$(get m "$MANAGER" "$P")"
grep -q 'data-review-composer' "$B" && grep -q 'data-embed-open' "$B" && ! grep -q '<iframe' "$B" && ! grep -q 'data-mode-button' "$B"; ok "Widok ekranów z linkiem do prototypu, bez ramki i trybu Przeglądaj" $?
grep -q 'Oczekuje na decyzję' "$B"; ok "Rewizja 1: oczekuje na decyzję" $?

echo "== 3. Uwaga z pinezką na ekranie =="
SCREEN=$(admin screen "$Q")
check "Uwaga z pinezką (JSON)" 201 "$(jpost m "$MANAGER" "$TM" "$P/comments" -d body='Za mały kontrast przycisku' -d screen_id="$SCREEN" -d x=0.25 -d y=0.4 -d request_id="$(uuid)")"
S=$(state)
check "Uwaga: ekran, rewizja 1, pinezka" "$SCREEN|1|true" "$(echo "$S" | jget thread.screen)|$(echo "$S" | jget thread.revision)|$(echo "$S" | jget thread.pin)"
THREAD=$(echo "$S" | jget thread.id)
check "Obcy ekran (projekt F) → 422" 422 "$(jpost m "$MANAGER" "$TM" "$P/comments" -d body='x' -d screen_id=999999 -d x=0.1 -d y=0.1)"

echo "== 4. Odpowiedź, statusy, ponowne otwarcie =="
get u "$MEMBER" "$P" >/dev/null; TU=$(token)
check "Odpowiedź członka" 201 "$(jpost u "$MEMBER" "$TU" "$P/threads/$THREAD/replies" -d body='Poprawię w Figmie' -d request_id="$(uuid)")"
check "Nowy → W trakcie" 200 "$(jpost m "$MANAGER" "$TM" "$P/threads/$THREAD/status" -d status=in_progress -d version=0)"
check "W trakcie → Rozwiązany" 200 "$(jpost m "$MANAGER" "$TM" "$P/threads/$THREAD/status" -d status=resolved -d version=1)"
check "Ponowne otwarcie" 200 "$(jpost m "$MANAGER" "$TM" "$P/threads/$THREAD/status" -d status=open -d version=2)"
S=$(state)
check "Stan: status|wiadomości|historia" "open|2|3" "$(echo "$S" | jget thread.status)|$(echo "$S" | jget thread.messages)|$(echo "$S" | jget thread.events)"
check "Nieaktualna wersja → konflikt" 409 "$(jpost m "$MANAGER" "$TM" "$P/threads/$THREAD/status" -d status=resolved -d version=0)"

echo "== 5. Ograniczony link klienta =="
check "Utworzenie linku (komentarz + akceptacja)" 200 "$(post m "$MANAGER" "$TM" "$P/links" -d label=Odbiór -d can_comment=1 -d can_approve=1)"
grep -q 'Bez terminu ważności' "$B" && ! grep -q 'name="expires_in"' "$B"; ok "Link recenzji bez terminu ważności" $?
RT=$(grep -o "/r/[A-Za-z0-9_-]*" "$B" | head -1 | sed 's#/r/##')
[ -n "$RT" ]; ok "Adres linku pokazany raz po utworzeniu" $?
check "Wejście klienta" 302 "$(gget g "/r/$RT")"
check "Recenzja klienta" 200 "$(gget g /review)"; TG=$(token)
grep -q 'nie daje dostępu do pliku w Figmie' "$B"; ok "Informacja: Figma osobno" $?
gpost g "$TG" /review/name -d name='Pani Klient' >/dev/null
check "Uwaga klienta" 201 "$(gpost g "$TG" /review/comments -d body='Logo większe' -d context='Nagłówek' -d request_id="$(uuid)")"
check "Klient: odpowiedź w wątku obcego projektu → 404" 404 "$(gpost g "$TG" "/review/threads/$FT/replies" -d body=x -d request_id="$(uuid)")"
check "Klient: panel obcego projektu → logowanie" 302 "$(gget g "/app/design-review/$F")"

echo "== 6. Poprawki A → rewizja B → akceptacja B → rewizja C =="
check "Klient: poprawki do rewizji 1 (A)" 201 "$(gpost g "$TG" /review/decision $(dec changes_requested 1) -d note='Popraw logo')"
check "Opiekun: nowa rewizja 2 (B)" 302 "$(post m "$MANAGER" "$TM" "$P/revisions")"
check "Klient: decyzja dla A po utworzeniu B → 409" 409 "$(gpost g "$TG" /review/decision $(dec approved 1))"
check "Klient: akceptacja rewizji 2 (B)" 201 "$(gpost g "$TG" /review/decision $(dec approved 2))"
check "Opiekun: nowa rewizja 3 (C)" 302 "$(post m "$MANAGER" "$TM" "$P/revisions")"
S=$(state)
check "Rewizje: A do poprawy, B zaakceptowana, C oczekuje" '["1:changes_requested","2:approved","3:draft"]' "$(echo "$S" | jget revisions)"
check "Decyzje: rewizja, decyzja, aktor, identyfikacja" '["1:changes_requested:client:review_link","2:approved:client:review_link"]' "$(echo "$S" | jget decisions)"
get m "$MANAGER" "$P" >/dev/null
grep -q 'Decyzja — Rewizja 3' "$B" && grep -q 'data-history-revision="2"' "$B" && grep -q 'data-history-revision="1"' "$B"; ok "Panel: karta rewizji 3, historia 1 i 2" $?
grep -q 'nazwa podana przez osobę, niepotwierdzona\|link, nazwa deklarowana' "$B"; ok "Poziom identyfikacji opisany (nazwa deklarowana)" $?

echo "== 7. Powiadomienia: bez duplikatów, odebrany dostęp =="
NC=$(admin notif-count "$Q")
[ "$NC" -gt 0 ]; ok "Powiadomienia powstały ($NC)" $?
check "Ponowna obsługa zdarzenia → 0 nowych" 0 "$(admin renotify "$Q")"
check "Liczba bez zmian" "$NC" "$(admin notif-count "$Q")"
NM=$(admin notif "$U_MEMBER" "$Q")
check "Członek: powiadomienie → kontekst" 302 "$(get u "$MEMBER" "/app/notifications/$NM")"
admin remove-member "$Q" "$U_MEMBER" >/dev/null
check "Po odebraniu dostępu: stary link powiadomienia → 404" 404 "$(get u "$MEMBER" "/app/notifications/$NM")"

echo "== 8. Unieważnienie linku klienta, sekrety =="
LINK=$(admin link "$Q")
check "Opiekun unieważnia link" 302 "$(post m "$MANAGER" "$TM" "$P/links/$LINK/revoke")"
check "Klient po unieważnieniu: recenzja → 403" 403 "$(gget g /review)"
check "Klient po unieważnieniu: zapis → 403" 403 "$(gpost g "$TG" /review/comments -d body=x)"
check "Ponowne wejście linkiem → 404" 404 "$(gget g3 "/r/$RT")"
if [ -f "$LARAVEL_LOG" ]; then tail -c +"$((LOG_OFFSET+1))" "$LARAVEL_LOG" > "$WORK/log"; else : > "$WORK/log"; fi
! grep -qF "$RT" "$WORK/log" && ! grep -qF "$OLD" "$WORK/log"; ok "Brak tokenów w laravel.log (część z czasu testu)" $?

echo "== 9. Treść i stary link po całym scenariuszu =="
check "Treść bez zmian" "Treść po zmianie klienta" "$(admin value "$FIELD")"
check "Stary link Treści działa" 200 "$(gget c "/p/$OLD")"
check "Stary link aktywny (unieważnienie recenzji go nie dotyka)" "content|active" "$(admin access "$(json "$IDS" old_access)")"

echo "SCENARIUSZ ODBIORCZY: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
