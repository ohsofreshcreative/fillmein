#!/usr/bin/env bash
#
# Design Review, etap 11 — powiadomienia w panelu i historia projektu: odbiorcy, deduplikacja (ponowna obsługa,
# ponowienie żądania), odczyt (pojedynczy, wszystkie), link bezpośredni do kontekstu, cudze powiadomienia,
# odebranie dostępu odbiorcy (lista, licznik, wcześniejszy link), historia (aktor, czas, kontekst, bez tokenów), XSS.
#
# Użycie: bash tests/notifications/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-notifications.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmin.XXXXXX"); chmod 700 "$WORK"
B="$WORK/b"

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f "$IDS"; rm -rf "$WORK"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/notifications/fixtures.php" || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$1" "$2"; }
SITE=$(json "$MANIFEST" site)
MANAGER=$(json "$MANIFEST" cookies.manager); MEMBER=$(json "$MANIFEST" cookies.member); OUTSIDER=$(json "$MANIFEST" cookies.outsider)
U_MEMBER=$(json "$MANIFEST" users.member); U_MANAGER=$(json "$MANIFEST" users.manager); U_ADMIN=$(json "$MANIFEST" users.admin)
N=$(json "$IDS" n); O=$(json "$IDS" o); T1=$(json "$IDS" t1); T2=$(json "$IDS" t2)
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
admin() { fmiwp eval-file "$THEME/tests/notifications/admin.php" "$@" 2>/dev/null | tail -1; }
get() { curl -s -o "$B" -D "$WORK/h" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$1" -c "$WORK/jar-$1" -H "Cookie: $2" "$SITE$3"; }
loc() { grep -i '^location:' "$WORK/h" | tr -d '\r' | sed 's/^[Ll]ocation: //'; }
token() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
post() { local s="$1" c="$2" t="$3" path="$4"; shift 4; curl -s -o /dev/null -w '%{http_code}' --max-time 60 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "Cookie: $c" -H "X-CSRF-TOKEN: $t" -X POST "$@" "$SITE$path"; }

echo "== Serwis =="
fmiwp eval-file "$THEME/tests/notifications/service.php"; ok "Odbiorcy, deduplikacja, historia (serwis)" $?

echo "== Lista i licznik (członek) =="
check "Członek: /app/notifications" 200 "$(get u "$MEMBER" /app/notifications)"; TU=$(token)
check "Członek: 3 powiadomienia z N (uwaga gościa, uwaga opiekuna, decyzja)" 3 "$(grep -o 'data-notification="[0-9]*"' "$B" | wc -l | tr -d ' ')"
check "Licznik w nawigacji" 3 "$(grep -o 'data-nav-count>[0-9]*' "$B" | head -1 | grep -o '[0-9]*$')"
! grep -q '<script>alert(1)</script>' "$B" && grep -q '&lt;script&gt;alert(1)&lt;/script&gt;' "$B"; ok "Fragment uwagi escapowany (XSS)" $?
grep -q 'Ewa &lt;b&gt;Gość&lt;/b&gt; (gość) dodał(a) uwagę' "$B"; ok "Aktor-gość: nazwa deklarowana, escapowana" $?
! grep -qF "$(json "$IDS" token)" "$B"; ok "Brak tokenu linku na liście" $?

echo "== Link bezpośredni i odczyt =="
NC=$(admin id "$U_MEMBER" review.comment "$T1"); ND=$(admin id "$U_MEMBER" review.decision); NM=$(admin id "$U_MEMBER" review.comment "$T2")
check "Otwarcie uwagi → 302" 302 "$(get u "$MEMBER" "/app/notifications/$NC")"
check "Link do kontekstu: wątek uwagi" "$SITE/app/design-review/$N#uwaga-$T1" "$(loc)"
check "Po otwarciu: przeczytane" read "$(admin read "$NC")"
check "Otwarcie decyzji → karta decyzji" "$SITE/app/design-review/$N#decyzja" "$(get u "$MEMBER" "/app/notifications/$ND" >/dev/null; loc)"
check "Kontekst otwiera się (200)" 200 "$(get u "$MEMBER" "/app/design-review/$N")"
get u "$MEMBER" /app/notifications >/dev/null; TU=$(token)
check "Licznik po dwóch otwarciach" 1 "$(grep -o 'data-nav-count>[0-9]*' "$B" | head -1 | grep -o '[0-9]*$')"
check "Oznacz jako przeczytane (POST)" 302 "$(post u "$MEMBER" "$TU" "/app/notifications/$NM/read")"
check "Pojedyncze oznaczenie zapisane" read "$(admin read "$NM")"
get u "$MEMBER" /app/notifications >/dev/null
! grep -q 'data-nav-count' "$B"; ok "Brak licznika, gdy wszystko przeczytane" $?
check "Filtr nieprzeczytane: pusto" 0 "$(get u "$MEMBER" '/app/notifications?filtr=nieprzeczytane' >/dev/null; grep -o 'data-notification="[0-9]*"' "$B" | wc -l | tr -d ' ')"

echo "== Cudze powiadomienia =="
NMG=$(admin id "$U_MANAGER" review.comment "$T1")
check "Członek otwiera powiadomienie opiekuna → 404" 404 "$(get u "$MEMBER" "/app/notifications/$NMG")"
check "Członek oznacza cudze → 404" 404 "$(post u "$MEMBER" "$TU" "/app/notifications/$NMG/read")"
check "Cudze nadal nieprzeczytane" unread "$(admin read "$NMG")"
check "Bez CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $MEMBER" -X POST "$SITE/app/notifications/read-all")"
check "Bez logowania → logowanie (302)" 302 "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/app/notifications")"
check "Nieprzypisany: lista pusta" 0 "$(get o "$OUTSIDER" /app/notifications >/dev/null; grep -o 'data-notification="[0-9]*"' "$B" | wc -l | tr -d ' ')"

echo "== Oznacz wszystkie (opiekun) =="
get m "$MANAGER" /app/notifications >/dev/null; TM=$(token)
check "Opiekun: nieprzeczytane w N przed" 2 "$(admin unread "$U_MANAGER" "$N")"
check "Oznacz wszystkie" 302 "$(post m "$MANAGER" "$TM" /app/notifications/read-all)"
check "Opiekun: nieprzeczytane po" 0 "$(admin unread "$U_MANAGER" "$N")"

echo "== Odebranie dostępu odbiorcy =="
NC2=$(admin id "$U_MEMBER" review.comment "$T1")
admin remove-member "$N" "$U_MEMBER" >/dev/null
check "Po usunięciu z projektu: wcześniejszy link → 404" 404 "$(get u "$MEMBER" "/app/notifications/$NC2")"
check "Po usunięciu: lista bez powiadomień N" 0 "$(get u "$MEMBER" /app/notifications >/dev/null; grep -o 'data-notification="[0-9]*"' "$B" | wc -l | tr -d ' ')"
check "Po usunięciu: Design Review N → 404" 404 "$(get u "$MEMBER" "/app/design-review/$N")"

echo "== Historia projektu =="
check "Historia N (opiekun), filtr Design Review" 200 "$(get m "$MANAGER" "/app/projects/$N/activity?typ=recenzja")"
grep -q 'Dodano uwagę w Design Review' "$B" && grep -q 'Dodano odpowiedź w Design Review' "$B" && grep -q 'Zapisano decyzję o rewizji' "$B"; ok "Zdarzenia: uwaga, odpowiedź, decyzja" $?
grep -q 'Ewa &lt;b&gt;Gość&lt;/b&gt; (gość, nazwa deklarowana)' "$B"; ok "Aktor-gość z oznaczeniem nazwy deklarowanej" $?
grep -q "Rewizja 1 · Uwaga $T1" "$B" && grep -q 'Rewizja 1 · Do poprawy' "$B"; ok "Kontekst: rewizja, uwaga, decyzja" $?
grep -q '<time' "$B"; ok "Czas zdarzeń" $?
! grep -qF "$(json "$IDS" token)" "$B"; ok "Brak tokenu w historii" $?
grep -q 'Utworzono link recenzji dla klienta' "$(get m "$MANAGER" "/app/projects/$N/activity?typ=dostep" >/dev/null; echo "$B")"; ok "Historia dostępu: link recenzji (bez tokenu)" $?
check "Historia: nieprzypisany → 404" 404 "$(get o "$OUTSIDER" "/app/projects/$N/activity")"

echo "POWIADOMIENIA: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
