#!/usr/bin/env bash
# Krok 0 — POST-y z FormRequest: pracownik bez przypisania dostaje 404 (jak przy GET), a nie 403.
# Wymaga manifestu z tests/stage03/setup.php (dane testowe sprzątane przez teardown.php).

set -uo pipefail

MANIFEST=/tmp/fmi-stage03-fixtures.json
[ -f "$MANIFEST" ] || { echo "BLOCKED: brak $MANIFEST"; exit 2; }

json() { php -r '$m=json_decode(file_get_contents("/tmp/fmi-stage03-fixtures.json"),true); foreach(explode(".",$argv[1]) as $k){$m=$m[$k];} echo $m;' "$1"; }

SITE=$(json site)
PA=$(json projects.a)
PAGE_A=$(json pages.a)
C_OUTSIDER=$(json cookies.outsider)
C_MEMBER=$(json cookies.member)

PASS=0; FAIL=0

check() { # check <opis> <oczekiwany> <faktyczny>
  if [ "$2" = "$3" ]; then
    echo "  PASS  $1 ($3)"; PASS=$((PASS+1))
  else
    echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1))
  fi
}

# Sesja + token CSRF z dowolnej strony panelu dostępnej dla danego konta.
session() { # session <cookie> <jar> → wypisuje token CSRF
  curl -s --max-time 25 -c "$2" -b "$2" -H "Cookie: $1" "$SITE/app/projects" \
    | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'
}

post() { # post <cookie> <jar> <token> <url> [dane...]
  local cookie=$1 jar=$2 token=$3 url=$4; shift 4
  curl -s -o /dev/null -w '%{http_code}' --max-time 25 -c "$jar" -b "$jar" -H "Cookie: $cookie" \
    -X POST -d "_token=$token" "$@" "$url"
}

echo "== Pracownik bez przypisania: POST → 404 =="
JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
TOKEN=$(session "$C_OUTSIDER" "$JAR")

if [ -z "$TOKEN" ]; then
  echo "  FAIL  brak tokenu CSRF w panelu pracownika bez przypisania"; FAIL=$((FAIL+1))
else
  check "Dodanie podstrony (StorePageRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/pages" --data-urlencode 'name=Obca')"
  check "Edycja podstrony (UpdatePageRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/pages/$PAGE_A" --data-urlencode 'name=Obca')"
  check "Duplikowanie podstrony (DuplicatePageRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/pages/$PAGE_A/duplicate")"
  check "Kolejność podstron (ReorderPagesRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/pages/reorder" -d "order[]=$PAGE_A")"
  check "Ustawienia projektu (UpdateProjectRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA" --data-urlencode 'name=Obcy')"
  check "Status projektu (UpdateProjectStatusRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/status" -d 'status=archived')"
  check "Zespół projektu (SyncProjectMembersRequest)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/members")"
  check "Reguła warstwy Figmy (UpdateFigmaNodeRuleRequest, regresja)" 404 "$(post "$C_OUTSIDER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA/pages/$PAGE_A/figma/rules" -d 'node=1:2&action=exclude')"
fi
rm -f "$JAR"

echo "== Przypisany pracownik bez capability: nadal 403 (projekt nie jest ukrywany) =="
JAR=$(mktemp "${TMPDIR:-/tmp}/fmi-jar.XXXXXX")
TOKEN=$(session "$C_MEMBER" "$JAR")

if [ -z "$TOKEN" ]; then
  echo "  FAIL  brak tokenu CSRF w panelu przypisanego pracownika"; FAIL=$((FAIL+1))
else
  check "Ustawienia projektu bez fmi_create_projects/opieki" 403 "$(post "$C_MEMBER" "$JAR" "$TOKEN" "$SITE/app/projects/$PA" --data-urlencode 'name=Zmiana')"
fi
rm -f "$JAR"

echo
echo "Krok 00 — HTTP: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ] || exit 1
