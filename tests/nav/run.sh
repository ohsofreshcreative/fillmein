#!/usr/bin/env bash
#
# Nawigacja panelu (Design Review, etap 02): menu Pulpit, Projekty, Treści, Użytkownicy, Figma; stare adresy
# /app/projects/* nadal otwierają Treści tych samych projektów; widoczność menu i serwerowa odmowa wg roli.
# Dane: tests/stage03/setup.php + tests/nav/extra.php, zawsze sprzątane.
#
# Użycie: bash tests/nav/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
EXTRA=/tmp/fmi-nav-extra.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fminav.XXXXXX")
chmod 700 "$WORK"
B="$WORK/b"; H="$WORK/h"

cleanup() {
  fmiwp eval-file "$THEME/tests/nav/extra.php" teardown
  fmiwp eval-file "$THEME/tests/stage03/teardown.php"
}

fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/nav/extra.php" setup || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo $m;' "$1" "$2"; }
SITE=$(json "$MANIFEST" site)
PA=$(json "$MANIFEST" projects.a); PB=$(json "$MANIFEST" projects.b)
PAGE_A=$(json "$MANIFEST" pages.a)
NAME_A="TEST HTTP projekt A $(json "$MANIFEST" suffix)"; NAME_B="TEST HTTP projekt B $(json "$MANIFEST" suffix)"
C_NONE=$(json "$EXTRA" cookie); CLIENT=$(json "$EXTRA" client_path)
PASS=0; FAIL=0

get() {
  local auth=()
  [ -n "$1" ] && auth=(-H "Cookie: $1")
  curl -s -o "$B" -D "$H" -w '%{http_code}' --max-time 30 "${auth[@]+"${auth[@]}"}" "$SITE$2"
}
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -qF -- "$1" "$B"; }
# Pozycje menu w kolejności z nawigacji głównej (etykiety linków w <nav aria-label="Główna nawigacja">).
menu() { php -r '$h=file_get_contents($argv[1]); $h=preg_replace("~<span[^>]*data-nav-count[^>]*>.*?</span></span>~s","",$h); preg_match("~<nav[^>]*aria-label=\"Główna nawigacja\"[^>]*>(.*?)</nav>~s",$h,$m); preg_match_all("~<a\b[^>]*>(.*?)</a>~s",$m[1]??"",$a); echo implode("|", array_map(fn($x)=>trim(strip_tags($x)), $a[1]));' "$B"; }
current() { php -r '$h=file_get_contents($argv[1]); preg_match("~<nav[^>]*aria-label=\"Główna nawigacja\"[^>]*>(.*?)</nav>~s",$h,$m); preg_match_all("~<a\b[^>]*aria-current=\"page\"[^>]*>(.*?)</a>~s",$m[1]??"",$a); echo implode("|", array_map(fn($x)=>trim(strip_tags($x)), $a[1]));' "$B"; }

for role in admin manager member outsider; do
  C=$(json "$MANIFEST" "cookies.$role")
  echo "== $role =="
  check "Pulpit /app" 200 "$(get "$C" /app)"
  M=$(menu)
  case $role in
    admin) check "Menu: sześć pozycji w kolejności" "Pulpit|Projekty|Treści|Powiadomienia|Użytkownicy|Figma" "$M" ;;
    *) check "Menu bez Użytkowników i Figmy" "Pulpit|Projekty|Treści|Powiadomienia" "$M" ;;
  esac
  check "Aktywna pozycja na pulpicie" "Pulpit" "$(current)"

  check "Projekty (Design Review) /app/design-review" 200 "$(get "$C" /app/design-review)"
  check "Aktywna pozycja: Projekty" "Projekty" "$(current)"
  if [ "$role" = outsider ]; then
    ! has "$NAME_A"; ok "Design Review: bez projektu A spoza zakresu" $?
  else
    has "$NAME_A"; ok "Design Review: projekt A w zakresie" $?
  fi
  [ "$role" = member ] && { ! has "$NAME_B"; ok "Design Review: członek A nie widzi projektu B" $?; }

  check "Stary link listy /app/projects" 200 "$(get "$C" /app/projects)"
  check "Aktywna pozycja: Treści" "Treści" "$(current)"
  grep -q '<h1[^>]*>[[:space:]]*Treści' "$B"; ok "Lista treści pod starym adresem (nagłówek Treści)" $?

  if [ "$role" = outsider ]; then
    check "Stary link szczegółów A spoza zakresu → 404" 404 "$(get "$C" "/app/projects/$PA")"
    check "Stary link treści podstrony A spoza zakresu → 404" 404 "$(get "$C" "/app/projects/$PA/pages/$PAGE_A/content")"
  else
    check "Stary link szczegółów /app/projects/$PA" 200 "$(get "$C" "/app/projects/$PA")"
    has "$NAME_A"; ok "Szczegóły: te same dane (nazwa projektu A)" $?
    check "Aktywna pozycja w szczegółach: Treści" "Treści" "$(current)"
    check "Stary link treści podstrony" 200 "$(get "$C" "/app/projects/$PA/pages/$PAGE_A/content")"
  fi

  case $role in
    admin)
      check "Użytkownicy /app/users" 200 "$(get "$C" /app/users)"
      check "Aktywna pozycja: Użytkownicy" "Użytkownicy" "$(current)"
      check "Figma /app/settings/figma" 200 "$(get "$C" /app/settings/figma)"
      check "Aktywna pozycja: Figma" "Figma" "$(current)"
      ;;
    *)
      check "Użytkownicy: odmowa serwera" 403 "$(get "$C" /app/users)"
      check "Figma: odmowa serwera" 403 "$(get "$C" /app/settings/figma)"
      ;;
  esac
done

echo "== Gość i konto bez dostępu =="
for path in /app /app/design-review /app/projects "/app/projects/$PA" /app/users /app/settings/figma; do
  check "Gość $path → logowanie" 302 "$(get "" "$path")"
  grep -qi "^location: .*/app/login" "$H"; ok "Gość $path: przekierowanie na /app/login" $?
done
for path in /app/design-review /app/projects "/app/projects/$PA"; do
  check "Konto WP bez fmi_access $path" 403 "$(get "$C_NONE" "$path")"
done

echo "== Link klienta i adresy bez zmian =="
check "Link klienta /p/{token}" 200 "$(get "" "$CLIENT")"
has "$NAME_A"; ok "Link klienta otwiera ten sam projekt" $?
check "Nieistniejący projekt /app/projects/999999999" 404 "$(get "$(json "$MANIFEST" cookies.admin)" /app/projects/999999999)"
check "Projekt B przez stary adres (admin)" 200 "$(get "$(json "$MANIFEST" cookies.admin)" "/app/projects/$PB")"
has "$NAME_B"; ok "Projekt B: te same dane" $?

echo "== Telefon i klawiatura (HTML) =="
get "$(json "$MANIFEST" cookies.admin)" /app/design-review >/dev/null
has 'aria-label="Otwórz menu"'; ok "Przycisk menu mobilnego z etykietą" $?
has 'x-on:keydown.escape.window="nav = false"'; ok "Menu mobilne zamyka Escape" $?
has 'aria-label="Główna nawigacja"'; ok "Nawigacja jako <nav> z etykietą" $?

echo
echo "NAWIGACJA: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
