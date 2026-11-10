#!/usr/bin/env bash
#
# Usuwanie zaznaczonych projektów przez HTTP: uprawnienia, obcy projekt (wszystkie albo żaden), CSRF, skutki.
# Dane testowe z etapu 03, sprzątane na końcu.
#
# Użycie: bash tests/project-bulk/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

cleanup() {
  fmiwp eval-file "$THEME/tests/stage03/teardown.php" || echo "UWAGA: sprzątanie zgłosiło błąd — sprawdź dane testowe."
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: nie udało się utworzyć danych testowych."; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/stage03/cookies.php" >/dev/null

json() { php -r '$m=json_decode(file_get_contents("/tmp/fmi-stage03-fixtures.json"),true); foreach(explode(".",$argv[1]) as $k){$m=$m[$k];} echo $m;' "$1"; }

SITE=$(json site); PA=$(json projects.a); PB=$(json projects.b)
C_ADMIN=$(json cookies.admin); C_MANAGER=$(json cookies.manager); C_MEMBER=$(json cookies.member); C_OUTSIDER=$(json cookies.outsider)
URL="$SITE/app/projects/delete-selected"
JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
PASS=0; FAIL=0

check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
list() { curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $1" "$SITE/app/projects"; }
token() { grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
post() { # post <cookie> <token> <ciało>
  curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $1" -X POST -d "_token=$2&$3" "$URL"
}
alive() { fmiwp eval "echo App\Models\Project::query()->whereKey($1)->exists() ? 'tak' : 'nie';" 2>/dev/null | tail -1; }

echo "== Lista =="
HTML=$(list "$C_MANAGER"); T_MANAGER=$(printf '%s' "$HTML" | token)
check "Opiekun ma pole wyboru projektu A" 1 "$(printf '%s' "$HTML" | grep -c "value=\"$PA\"")"
HTML=$(list "$C_MEMBER"); T_MEMBER=$(printf '%s' "$HTML" | token)
check "Członek nie ma pola wyboru" 0 "$(printf '%s' "$HTML" | grep -c 'name="ids\[\]"')"

echo "== Odmowy =="
check "Gość → logowanie" 302 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST -d "ids[]=$PA" "$URL")"
check "Błędny token CSRF → 419" 419 "$(post "$C_MANAGER" zly "ids[]=$PA")"
: > "$JAR"
HTML=$(list "$C_MEMBER"); T_MEMBER=$(printf '%s' "$HTML" | token)
check "Członek bez prawa usuwania → 403" 403 "$(post "$C_MEMBER" "$T_MEMBER" "ids[]=$PA")"
: > "$JAR"
HTML=$(list "$C_OUTSIDER"); T_OUTSIDER=$(printf '%s' "$HTML" | token)
check "Pracownik bez przypisania → 404" 404 "$(post "$C_OUTSIDER" "$T_OUTSIDER" "ids[]=$PA")"
: > "$JAR"
HTML=$(list "$C_MANAGER"); T_MANAGER=$(printf '%s' "$HTML" | token)
# W danych etapu 03 opiekun prowadzi oba projekty, więc B dostaje innego opiekuna.
fmiwp eval "App\Models\Project::query()->whereKey($PB)->update(['manager_id' => null]);" 2>/dev/null
check "Własny i obcy razem → odmowa" 404 "$(post "$C_MANAGER" "$T_MANAGER" "ids[]=$PA&ids[]=$PB")"
check "Nieistniejący projekt → 404" 404 "$(post "$C_MANAGER" "$T_MANAGER" "ids[]=$PA&ids[]=999999999")"
check "Pusta lista → powrót z błędem" 302 "$(post "$C_MANAGER" "$T_MANAGER" "x=1")"
check "Po odmowach projekt A istnieje" tak "$(alive "$PA")"
check "Po odmowach projekt B istnieje" tak "$(alive "$PB")"

echo "== Usunięcie =="
check "Opiekun usuwa projekt A" 302 "$(post "$C_MANAGER" "$T_MANAGER" "ids[]=$PA")"
check "Projekt A zniknął" nie "$(alive "$PA")"
check "Projekt B został" tak "$(alive "$PB")"
check "Dane projektu A zostały w bazie (usunięcie miękkie)" 1 "$(fmiwp eval "echo App\Models\Project::withTrashed()->whereKey($PA)->count();" 2>/dev/null | tail -1)"
check "Wpis w dzienniku" 1 "$(fmiwp eval "echo App\Models\Activity::query()->where('project_id', $PA)->where('event', 'project.deleted')->count();" 2>/dev/null | tail -1)"

rm -f "$JAR"
echo
echo "USUWANIE PROJEKTÓW: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
