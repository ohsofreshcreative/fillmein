#!/usr/bin/env bash
# Etap 03 — testy HTTP na działającym LocalWP (http://fillmein.local).
# Wymaga manifestu z tests/stage03/setup.php. Weryfikuje kody odpowiedzi, IDOR, CSRF i zapis przez formularz.

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
[ -f "$MANIFEST" ] || { echo "BLOCKED: brak $MANIFEST"; exit 2; }

json() { php -r '$m=json_decode(file_get_contents("/tmp/fmi-stage03-fixtures.json"),true); $p=explode(".",$argv[1]); foreach($p as $k){$m=$m[$k];} echo $m;' "$1"; }

SITE=$(json site)
PA=$(json projects.a); PB=$(json projects.b)
PAGE_A=$(json pages.a); PAGE_B=$(json pages.b)
C_ADMIN=$(json cookies.admin); C_MANAGER=$(json cookies.manager)
C_MEMBER=$(json cookies.member); C_OUTSIDER=$(json cookies.outsider)

PASS=0; FAIL=0

code() { # code <cookie|-> <url>
  if [ "$1" = "-" ]; then
    curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$2"
  else
    curl -s -o /dev/null -w '%{http_code}' --max-time 25 -H "Cookie: $1" "$2"
  fi
}

check() { # check <opis> <oczekiwany> <faktyczny>
  if [ "$2" = "$3" ]; then
    echo "  PASS  $1 ($3)"; PASS=$((PASS+1))
  else
    echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1))
  fi
}

echo "== Gość (bez sesji) =="
check "GET /app/projects przekierowuje na logowanie" 302 "$(code - "$SITE/app/projects")"
check "GET /app/projects/$PA przekierowuje na logowanie" 302 "$(code - "$SITE/app/projects/$PA")"
check "GET /app/projects/create przekierowuje na logowanie" 302 "$(code - "$SITE/app/projects/create")"

echo "== Administrator =="
check "GET /app (pulpit)" 200 "$(code "$C_ADMIN" "$SITE/app")"
check "GET /app/projects (lista)" 200 "$(code "$C_ADMIN" "$SITE/app/projects")"
check "GET /app/projects?q=TEST&status=draft&sort=name&mine=1 (filtry)" 200 "$(code "$C_ADMIN" "$SITE/app/projects?q=TEST&status=draft&sort=name&mine=1")"
check "GET /app/projects?sort=zzz&dir=--&status=nope (parametry śmieciowe)" 200 "$(code "$C_ADMIN" "$SITE/app/projects?sort=zzz&dir=--&status=nope")"
check "GET /app/projects/create" 200 "$(code "$C_ADMIN" "$SITE/app/projects/create")"
check "GET /app/projects/$PA" 200 "$(code "$C_ADMIN" "$SITE/app/projects/$PA")"
check "GET /app/projects/$PA/edit" 200 "$(code "$C_ADMIN" "$SITE/app/projects/$PA/edit")"
check "GET /app/projects/$PA/pages/$PAGE_A/edit" 200 "$(code "$C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")"
check "GET /app/design-system (regresja)" 200 "$(code "$C_ADMIN" "$SITE/app/design-system")"
check "GET /app/users (regresja etapu 02)" 200 "$(code "$C_ADMIN" "$SITE/app/users")"
check "GET /app/profile (regresja etapu 02)" 200 "$(code "$C_ADMIN" "$SITE/app/profile")"

echo "== IDOR =="
check "Nieistniejący projekt → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/99999999")"
check "Podstrona projektu B pod projektem A → 404" 404 "$(code "$C_ADMIN" "$SITE/app/projects/$PA/pages/$PAGE_B/edit")"
check "Pracownik bez przypisania: projekt A → 404" 404 "$(code "$C_OUTSIDER" "$SITE/app/projects/$PA")"
check "Pracownik bez przypisania: podstrona A → 404" 404 "$(code "$C_OUTSIDER" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")"
check "Pracownik bez przypisania: lista projektów nadal 200" 200 "$(code "$C_OUTSIDER" "$SITE/app/projects")"

echo "== Uprawnienia przypisanego pracownika =="
check "Przypisany pracownik widzi projekt A" 200 "$(code "$C_MEMBER" "$SITE/app/projects/$PA")"
check "Przypisany pracownik edytuje podstronę A" 200 "$(code "$C_MEMBER" "$SITE/app/projects/$PA/pages/$PAGE_A/edit")"
check "Przypisany pracownik NIE wchodzi w ustawienia projektu (403)" 403 "$(code "$C_MEMBER" "$SITE/app/projects/$PA/edit")"
check "Przypisany pracownik nie widzi projektu B (404)" 404 "$(code "$C_MEMBER" "$SITE/app/projects/$PB")"
check "Opiekun wchodzi w ustawienia projektu A" 200 "$(code "$C_MANAGER" "$SITE/app/projects/$PA/edit")"

echo "== CSRF =="
check "POST bez tokenu CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST -H "Cookie: $C_ADMIN" -d "name=Bez+tokenu" "$SITE/app/projects/$PA/pages")"
check "POST z błędnym tokenem CSRF → 419" 419 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST -H "Cookie: $C_ADMIN" -d "_token=nieprawidlowy&name=Zly+token" "$SITE/app/projects/$PA/pages")"

echo "== Pełny zapis przez formularz (sesja + token z HTML) =="
JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
HTML=$(curl -s --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" "$SITE/app/projects/$PA")
TOKEN=$(printf '%s' "$HTML" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')

if [ -z "$TOKEN" ]; then
  echo "  FAIL  nie udało się odczytać tokenu CSRF ze strony projektu"; FAIL=$((FAIL+1))
else
  echo "  PASS  token CSRF obecny w formularzu dodawania podstrony"; PASS=$((PASS+1))

  ADD=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" \
    -X POST -d "_token=$TOKEN" \
    --data-urlencode "name=Podstrona z testu HTTP" \
    --data-urlencode "figma_frame_url=https://www.figma.com/design/abcdefghij12/A?node-id=7-7" \
    "$SITE/app/projects/$PA/pages")
  check "POST dodania podstrony z poprawnym tokenem → 302" 302 "$ADD"

  BAD=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" \
    -X POST -d "_token=$TOKEN" \
    --data-urlencode "name=Zly frame" \
    --data-urlencode "figma_frame_url=https://www.figma.com/design/abcdefghij12/A" \
    "$SITE/app/projects/$PA/pages")
  check "POST z linkiem do PLIKU (bez node-id) odrzucony walidacją → 302 z błędem" 302 "$BAD"

  DUP=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$JAR" -b "$JAR" -H "Cookie: $C_ADMIN" \
    -X POST -d "_token=$TOKEN" "$SITE/app/projects/$PA/pages/$PAGE_A/duplicate")
  check "POST duplikowania podstrony → 302" 302 "$DUP"
fi
rm -f "$JAR"

echo
echo "Etap 03 — HTTP: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ] || exit 1
