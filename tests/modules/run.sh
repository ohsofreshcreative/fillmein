#!/usr/bin/env bash
#
# Design Review, etap 03 — wspólny model projektów: moduły Treści / Design Review na jednym projekcie.
# Projekty: A (tylko Treści, jak wszystkie sprzed migracji), R (tylko Design Review), D (oba). Te same konta
# i członkostwa co w etapie 03 (tests/stage03/setup.php). Dane zawsze sprzątane (teardown etapu 03).
#
# Użycie: bash tests/modules/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
EXTRA=/tmp/fmi-modules-extra.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmimod.XXXXXX")
chmod 700 "$WORK"
B="$WORK/b"; H="$WORK/h"; JAR="$WORK/jar"

cleanup() {
  fmiwp eval-file "$THEME/tests/modules/fixtures.php" register >/dev/null 2>&1
  fmiwp eval-file "$THEME/tests/stage03/teardown.php"
  rm -f "$EXTRA"
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/modules/fixtures.php" setup || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_bool($m)?($m?"1":"0"):(is_scalar($m)?$m:json_encode($m));' "$1" "$2"; }
SITE=$(json "$MANIFEST" site); SUFFIX=$(json "$MANIFEST" suffix)
PA=$(json "$MANIFEST" projects.a); PR=$(json "$MANIFEST" projects.r); PD=$(json "$MANIFEST" projects.d)
PAGE_R=$(json "$MANIFEST" pages.r); PAGE_D=$(json "$MANIFEST" pages.d); PAGE_A=$(json "$MANIFEST" pages.a)
NAME_A="TEST HTTP projekt A $SUFFIX"; NAME_R="TEST moduły R $SUFFIX"; NAME_D="TEST moduły D $SUFFIX"
PASS=0; FAIL=0

get() { curl -s -o "$B" -D "$H" -w '%{http_code}' --max-time 30 -b "$JAR" -c "$JAR" -H "Cookie: $1" "$SITE$2"; }
post() { local c="$1" path="$2"; shift 2; curl -s -o "$B" -D "$H" -w '%{http_code}' --max-time 30 -b "$JAR" -c "$JAR" -H "Cookie: $c" -H "X-CSRF-TOKEN: $CSRF" -X POST "$@" "$SITE$path"; }
csrf() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -qF -- "$1" "$B"; }
state() { fmiwp eval-file "$THEME/tests/modules/fixtures.php" state "$WORK/state.json" 2>/dev/null; }

ADMIN=$(json "$MANIFEST" cookies.admin); MANAGER=$(json "$MANIFEST" cookies.manager)
MEMBER=$(json "$MANIFEST" cookies.member); OUTSIDER=$(json "$MANIFEST" cookies.outsider)

echo "== Listy: jeden projekt, dwa moduły =="
for who in ADMIN MEMBER; do
  C=${!who}
  get "$C" /app/projects >/dev/null
  has "$NAME_A"; ok "$who Treści: A (tylko Treści)" $?
  has "$NAME_D"; ok "$who Treści: D (oba)" $?
  ! has "$NAME_R"; ok "$who Treści: bez R (tylko Design Review)" $?
  get "$C" /app/design-review >/dev/null
  has "$NAME_R"; ok "$who Projekty: R" $?
  has "$NAME_D"; ok "$who Projekty: D" $?
  ! has "$NAME_A"; ok "$who Projekty: bez A" $?
  grep -q "/app/design-review/$PD\"" "$B"; ok "$who Projekty: D wskazuje recenzję tego samego projektu /app/design-review/$PD" $?
done

echo "== Trasy modułu Treści =="
for who in ADMIN MEMBER; do
  C=${!who}
  check "$who karta R (wspólna)" 200 "$(get "$C" "/app/projects/$PR")"
  has 'Moduł Treści jest wyłączony'; ok "$who karta R: informacja o wyłączonych Treściach" $?
  check "$who R treść podstrony → 404" 404 "$(get "$C" "/app/projects/$PR/pages/$PAGE_R/content")"
  check "$who R przegląd treści → 404" 404 "$(get "$C" "/app/projects/$PR/review")"
  check "$who R struktura → 404" 404 "$(get "$C" "/app/projects/$PR/pages/$PAGE_R/structure")"
  check "$who D treść podstrony" 200 "$(get "$C" "/app/projects/$PD/pages/$PAGE_D/content")"
  check "$who D karta" 200 "$(get "$C" "/app/projects/$PD")"
  check "$who A treść podstrony" 200 "$(get "$C" "/app/projects/$PA/pages/$PAGE_A/content")"
done
check "ADMIN R eksport → 404" 404 "$(get "$ADMIN" "/app/projects/$PR/exports")"
check "Link klienta A" 200 "$(get "" "$(json "$EXTRA" tokens.a)")"
check "Link klienta R (bez Treści) → 404" 404 "$(get "" "$(json "$EXTRA" tokens.r)")"
has 'Ten link jest nieaktywny'; ok "Link klienta R: strona nieaktywnego linku" $?

echo "== Zakres dostępu =="
for p in "$PR" "$PD" "$PA"; do
  check "Spoza projektu: karta $p → 404" 404 "$(get "$OUTSIDER" "/app/projects/$p")"
done
get "$OUTSIDER" /app/design-review >/dev/null
! has "$NAME_R" && ! has "$NAME_D"; ok "Spoza projektu: Projekty bez R i D" $?
check "Spoza projektu: treść D → 404" 404 "$(get "$OUTSIDER" "/app/projects/$PD/pages/$PAGE_D/content")"

state
check "Policy viewReview: opiekun R" 1 "$(json "$WORK/state.json" policy.r.manager.view)"
check "Policy viewReview: członek R" 1 "$(json "$WORK/state.json" policy.r.member.view)"
check "Policy viewReview: spoza projektu R" 0 "$(json "$WORK/state.json" policy.r.outsider.view)"
check "Policy manageReviews: opiekun (PM) R" 1 "$(json "$WORK/state.json" policy.r.manager.manage)"
check "Policy manageReviews: członek (pracownik) R odmowa" 0 "$(json "$WORK/state.json" policy.r.member.manage)"
check "Policy manageReviews: spoza projektu → 404" 404 "$(json "$WORK/state.json" policy.r.outsider.manage_status)"
USERS0=$(json "$WORK/state.json" users_total); PROJECTS0=$(json "$WORK/state.json" projects_total)

echo "== Formularze =="
check "Formularz nowego projektu" 200 "$(get "$MANAGER" /app/projects/create)"
has 'name="modules[]"' && has 'value="review"'; ok "Formularz: wybór modułów" $?
CSRF=$(csrf)
check "Brak modułu → odrzucone" 302 "$(post "$MANAGER" /app/projects -d "name=TEST bez modułu $SUFFIX" -d 'modules_sent=1')"
state
check "Brak modułu: projekt nie powstał" "$PROJECTS0" "$(json "$WORK/state.json" projects_total)"
check "Nowy projekt tylko Design Review" 302 "$(post "$MANAGER" /app/projects -d "name=TEST HTTP review $SUFFIX" -d 'modules_sent=1' -d 'modules[]=review')"
check "Nieznany moduł → odrzucone" 302 "$(post "$MANAGER" /app/projects -d "name=TEST zły moduł $SUFFIX" -d 'modules_sent=1' -d 'modules[]=nope')"
check "Bez pól modułów (stary formularz) → Treści" 302 "$(post "$MANAGER" /app/projects -d "name=TEST HTTP stary $SUFFIX")"
state
check "HTTP review: review=1" 1 "$(json "$WORK/state.json" "projects.TEST HTTP review $SUFFIX.review")"
check "HTTP review: content=0" 0 "$(json "$WORK/state.json" "projects.TEST HTTP review $SUFFIX.content")"
check "Stary formularz: content=1" 1 "$(json "$WORK/state.json" "projects.TEST HTTP stary $SUFFIX.content")"
check "Stary formularz: review=0" 0 "$(json "$WORK/state.json" "projects.TEST HTTP stary $SUFFIX.review")"
check "Zły moduł: projekt nie powstał" null "$(json "$WORK/state.json" "projects.TEST zły moduł $SUFFIX.id")"

check "Ustawienia A" 200 "$(get "$ADMIN" "/app/projects/$PA/edit")"
CSRF=$(csrf)
check "A: włączenie obu modułów" 302 "$(post "$ADMIN" "/app/projects/$PA" -d "name=$NAME_A" -d 'status=draft' -d 'modules_sent=1' -d 'modules[]=content' -d 'modules[]=review')"
state
check "A po zmianie: Treści" 1 "$(json "$WORK/state.json" "projects.$NAME_A.content")"
check "A po zmianie: Design Review" 1 "$(json "$WORK/state.json" "projects.$NAME_A.review")"
check "A: zmiana modułów w dzienniku" 1 "$(json "$WORK/state.json" activity_modules)"
PAGES_A=$(json "$WORK/state.json" "projects.$NAME_A.pages")
get "$MEMBER" /app/design-review >/dev/null; has "$NAME_A"; ok "A widoczny w Projektach tym samym ID" $?
check "A: wyłączenie Treści" 302 "$(post "$ADMIN" "/app/projects/$PA" -d "name=$NAME_A" -d 'status=draft' -d 'modules_sent=1' -d 'modules[]=review')"
check "A bez Treści: treść podstrony → 404" 404 "$(get "$ADMIN" "/app/projects/$PA/pages/$PAGE_A/content")"
state
check "A bez Treści: podstrony zachowane" "$PAGES_A" "$(json "$WORK/state.json" "projects.$NAME_A.pages")"
get "$ADMIN" "/app/projects/$PA/edit" >/dev/null; CSRF=$(csrf)
check "A: ponowne włączenie Treści" 302 "$(post "$ADMIN" "/app/projects/$PA" -d "name=$NAME_A" -d 'status=draft' -d 'modules_sent=1' -d 'modules[]=content')"
check "A: treść podstrony znowu dostępna" 200 "$(get "$ADMIN" "/app/projects/$PA/pages/$PAGE_A/content")"
check "Członek (pracownik) nie zmienia modułów → 403" 403 "$(post "$MEMBER" "/app/projects/$PA" -d "name=$NAME_A" -d 'status=draft' -d 'modules_sent=1' -d 'modules[]=review')"

state
check "Bez nowych kont (brak kopii użytkowników)" "$USERS0" "$(json "$WORK/state.json" users_total)"

echo
echo "MODUŁY: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
