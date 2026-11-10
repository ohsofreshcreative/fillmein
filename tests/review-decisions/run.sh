#!/usr/bin/env bash
#
# Design Review, etap 10 — decyzje o rewizji: akceptacja A → nowa B (rozdzielenie stanów, historia), brak uprawnień
# (członek, nieprzypisany, link bez akceptacji, link odwołany, obcy projekt), równoległe żądania (jedna wygrywa),
# ten sam URL Figmy ze zmienioną zawartością (atrapa API w teście serwisu) i jawne ograniczenie wersji w widoku.
#
# Użycie: bash tests/review-decisions/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-review-decisions.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmird.XXXXXX"); chmod 700 "$WORK"
B="$WORK/b"

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f "$IDS"; rm -rf "$WORK"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-decisions/fixtures.php" || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$1" "$2"; }
SITE=$(json "$MANIFEST" site)
MANAGER=$(json "$MANIFEST" cookies.manager); MEMBER=$(json "$MANIFEST" cookies.member); OUTSIDER=$(json "$MANIFEST" cookies.outsider)
D=$(json "$IDS" d); E=$(json "$IDS" e)
tok() { json "$IDS" "tokens.$1"; }
aid() { json "$IDS" "access.$1"; }
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
admin() { fmiwp eval-file "$THEME/tests/review-decisions/admin.php" "$@" 2>/dev/null | tail -1; }
state() { admin state "$D"; }
uuid() { uuidgen | tr 'A-Z' 'a-z'; }
token() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
# Pracownik: słoik ciasteczek + ciasteczka WordPressa.
get() { curl -s -o "$B" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$1" -c "$WORK/jar-$1" -H "Cookie: $2" "$SITE$3"; }
jpost() { local s="$1" c="$2" t="$3" path="$4"; shift 4; curl -s -o "$WORK/out-$s" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "Cookie: $c" -H "X-CSRF-TOKEN: $t" -H 'Accept: application/json' "$@" "$SITE$path"; }
# Gość: własny słoik, bez ciasteczek WordPressa.
enter() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 -b "$WORK/jar-$1" -c "$WORK/jar-$1" "$SITE/r/$2"; }
gget() { curl -s -o "$B" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$1" -c "$WORK/jar-$1" "$SITE$2"; }
gpost() { local s="$1" t="$2" path="$3"; shift 3; curl -s -o "$WORK/out-$s" -w '%{http_code}' --max-time 60 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "X-CSRF-TOKEN: $t" -H 'Accept: application/json' "$@" "$SITE$path"; }
dec() { echo -d "decision=$1" -d "revision=$2" -d "request_id=$(uuid)" -d confirm=1; }

admin clear-limits >/dev/null

echo "== Serwis (atrapa Figmy: zmiana zawartości pod tym samym URL) =="
fmiwp eval-file "$THEME/tests/review-decisions/service.php"; ok "Reguły serwisu, wersje i uprawnienia" $?

P="/app/design-review/$D"
echo "== Panel: brak uprawnień =="
get m "$MANAGER" "$P" >/dev/null; TM=$(token)
grep -q 'data-decision-form' "$B"; ok "Opiekun widzi formularz decyzji" $?
grep -q 'Oczekuje na decyzję' "$B"; ok "Rewizja 1: „Oczekuje na decyzję”" $?
get u "$MEMBER" "$P" >/dev/null; TU=$(token)
! grep -q 'data-decision-form' "$B"; ok "Członek bez fmi_manage_reviews: bez formularza" $?
check "Członek: zapis decyzji → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/decision" $(dec approved 1))"
get o "$OUTSIDER" "/app" >/dev/null; TO=$(token)
check "Nieprzypisany: zapis decyzji → 404" 404 "$(jpost o "$OUTSIDER" "$TO" "$P/decision" $(dec approved 1))"
check "Opiekun D: obcy projekt E → 404" 404 "$(jpost m "$MANAGER" "$TM" "/app/design-review/$E/decision" $(dec approved 1))"
check "Bez CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $MANAGER" -H 'Accept: application/json' $(dec approved 1) "$SITE$P/decision")"
check "Poprawki bez opisu → 422" 422 "$(jpost m "$MANAGER" "$TM" "$P/decision" $(dec changes_requested 1))"
check "Bez potwierdzenia → 422" 422 "$(jpost m "$MANAGER" "$TM" "$P/decision" -d decision=approved -d revision=1 -d request_id=$(uuid))"
check "Nieistniejąca rewizja → 404" 404 "$(jpost m "$MANAGER" "$TM" "$P/decision" $(dec approved 9))"
check "Członek: nowa rewizja → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/revisions" -X POST)"
check "Stan bez zmian" '{"revisions":["1:draft"],"decisions":[]}' "$(state)"

echo "== Gość: link bez akceptacji, odwołany, obcy =="
enter na "$(tok noapprove)" >/dev/null; gget na /review >/dev/null; TNA=$(token)
! grep -q 'data-decision-form' "$B"; ok "Link bez akceptacji: bez formularza decyzji" $?
gpost na "$TNA" /review/name -d name=Ola >/dev/null
check "Link bez akceptacji: zapis → 403" 403 "$(gpost na "$TNA" /review/decision $(dec approved 1))"
enter rv "$(tok revoked)" >/dev/null; gget rv /review >/dev/null; TRV=$(token)
gpost rv "$TRV" /review/name -d name=Ola >/dev/null
admin revoke "$(aid revoked)" >/dev/null
check "Link odwołany w trakcie sesji: zapis → 403" 403 "$(gpost rv "$TRV" /review/decision $(dec approved 1))"
check "Stan bez zmian po odmowach gościa" '{"revisions":["1:draft"],"decisions":[]}' "$(state)"

echo "== Równoległe decyzje o rewizji 1 (6 żądań: opiekun i gość) =="
enter g "$(tok full)" >/dev/null; gget g /review >/dev/null; TG=$(token)
check "Gość bez nazwy: zapis → 422" 422 "$(gpost g "$TG" /review/decision $(dec approved 1))"
gpost g "$TG" /review/name -d name='Anna Kowalska' >/dev/null
gget g /review >/dev/null
grep -q 'data-decision-form' "$B" && grep -q 'nie jest podpis elektroniczny' "$B" && grep -q 'niepotwierdzon' "$B"; ok "Gość z akceptacją: formularz z opisem identyfikacji (nie podpis)" $?
for i in 1 2 3; do
  ( jpost "p$i" "$MANAGER" "$TM" "$P/decision" -b "$WORK/jar-m" $(dec approved 1) > "$WORK/code-p$i"; echo >> "$WORK/code-p$i" ) &
  ( gpost "q$i" "$TG" /review/decision -b "$WORK/jar-g" $(dec changes_requested 1) -d note="Poprawka $i" > "$WORK/code-q$i"; echo >> "$WORK/code-q$i" ) &
done
wait
CODES=$(cat "$WORK"/code-p* "$WORK"/code-q* | sort | uniq -c | awk '{print $2"x"$1}' | tr '\n' ' ')
echo "  INFO  kody odpowiedzi: $CODES"
WINS=$(cat "$WORK"/code-p* "$WORK"/code-q* | grep -c 201)
LOSS=$(cat "$WORK"/code-p* "$WORK"/code-q* | grep -c 409)
check "Dokładnie jedna decyzja wygrywa (201), reszta konflikt (409)" "1/5" "$WINS/$LOSS"
N=$(state | php -r '$s=json_decode(stream_get_contents(STDIN),true); echo count($s["decisions"]);')
check "W bazie jedna decyzja dla rewizji 1" 1 "$N"
WIN=$(state | php -r '$s=json_decode(stream_get_contents(STDIN),true); $d=$s["decisions"][0]; echo $s["revisions"][0], "|", $d["decision"];')
case "$WIN" in "1:approved|approved"|"1:changes_requested|changes_requested") ok "Status rewizji = zwycięska decyzja ($WIN)" 0;; *) ok "Status rewizji = zwycięska decyzja ($WIN)" 1;; esac
grep -h 'Rewizja 1 ma ju' "$WORK"/out-p* "$WORK"/out-q* >/dev/null; ok "Przegrane żądania: komunikat o istniejącej decyzji" $?
check "Ponowna decyzja dla rewizji 1 → 409" 409 "$(jpost m "$MANAGER" "$TM" "$P/decision" $(dec changes_requested 1) -d note=x)"

echo "== Nowa rewizja B: oddzielny stan, historia =="
check "Opiekun: utwórz rewizję 2" 302 "$(curl -s -o /dev/null -w '%{http_code}' -b "$WORK/jar-m" -c "$WORK/jar-m" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" -X POST "$SITE$P/revisions")"
S=$(state | php -r '$s=json_decode(stream_get_contents(STDIN),true); echo implode(",",$s["revisions"]), "|", count($s["decisions"]);')
case "$S" in "1:approved,2:draft|1"|"1:changes_requested,2:draft|1") ok "Rewizja 1 zachowuje decyzję, rewizja 2 oczekuje ($S)" 0;; *) ok "Rewizja 1 zachowuje decyzję, rewizja 2 oczekuje ($S)" 1;; esac
check "Decyzja dla starej rewizji 1 → 409" 409 "$(gpost g "$TG" /review/decision $(dec approved 1))"
get m "$MANAGER" "$P" >/dev/null
grep -q 'Decyzja — Rewizja 2' "$B" && grep -q 'data-decision-form' "$B"; ok "Panel: formularz dla rewizji 2" $?
grep -q 'data-history-revision="1"' "$B"; ok "Panel: historia z decyzją rewizji 1" $?
gget g /review >/dev/null
grep -q 'Decyzja — Rewizja 2' "$B" && grep -q 'name="revision" value="2"' "$B"; ok "Gość: formularz dotyczy rewizji 2" $?
check "Gość akceptuje rewizję 2" 201 "$(gpost g "$TG" /review/decision $(dec approved 2))"
LAST=$(state | php -r '$s=json_decode(stream_get_contents(STDIN),true); $d=end($s["decisions"]); echo implode(",",$s["revisions"]),"|",$d["rev"],"|",$d["actor"],"|",$d["access"],"|",$d["name"],"|",$d["ident"],"|",$d["user"] ?? "-";')
case "$LAST" in *",2:approved|2|client|$(aid full)|Anna Kowalska|review_link|-") ok "Rewizja 2: decyzja gościa — link, nazwa deklarowana, bez konta" 0;; *) ok "Rewizja 2: decyzja gościa ($LAST)" 1;; esac

echo "== Jawne ograniczenie wersji Figmy w widoku =="
get m "$MANAGER" "$P" >/dev/null
grep -q 'data-decision-version="unverified"' "$B" && grep -q 'data-version-limit' "$B" && grep -q 'link Figmy zawsze pokazuje bieżący stan pliku' "$B"; ok "Plik niedostępny w API → „wersja nieustalona” i opis ograniczenia" $?
grep -q 'Link recenzji — nazwa podana przez osobę, niepotwierdzona' "$B"; ok "Identyfikacja gościa opisana jako niepotwierdzona" $?
grep -q 'data-new-revision' "$B" && grep -q 'data-version-check-button' "$B"; ok "Przyciski: nowa rewizja, sprawdzenie wersji" $?
DID=$(fmiwp eval 'echo App\Models\ReviewDecision::query()->where("project_id",'"$D"')->where("revision_number",2)->value("id");' 2>/dev/null | tail -1)
LOC=$(curl -s -o /dev/null -D - -b "$WORK/jar-m" -c "$WORK/jar-m" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TM" -X POST "$SITE$P/decisions/$DID/check" | grep -i '^location:' | tr -d '\r')
echo "$LOC" | grep -q '#decyzja'; ok "Sprawdzenie wersji: powrót do karty decyzji" $?
get m "$MANAGER" "$P" >/dev/null
grep -q 'data-version-check="unknown"' "$B"; ok "Bez wersji z chwili decyzji → „nie można porównać”" $?
check "Sprawdzenie decyzji obcego projektu → 404" 404 "$(jpost m "$MANAGER" "$TM" "/app/design-review/$E/decisions/$DID/check" -X POST)"
check "Członek: sprawdzenie wersji → 403" 403 "$(jpost u "$MEMBER" "$TU" "$P/decisions/$DID/check" -X POST)"

echo "== Dziennik aktywności =="
LOGN=$(fmiwp eval 'echo App\Models\Activity::query()->where("project_id",'"$D"')->where("event","review.decision")->count();' 2>/dev/null | tail -1)
check "Wpisy review.decision (rewizja 1 i 2)" 2 "$LOGN"

echo "DECYZJE O REWIZJI: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
