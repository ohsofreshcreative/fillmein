#!/usr/bin/env bash
#
# Etap 14 — macierz bezpieczeństwa z planu security.php. Dla każdej trasy: gość, konto bez `fmi_access`, pracownik
# spoza projektu, CSRF, obcy element (IDOR), obcy token, a na końcu właściciel (dowód, że ścieżka istnieje, inaczej
# 404 z odmów nic by nie znaczyło). Wynik: /tmp/fmi-stage14-matrix.md, bez tokenów. Tokeny zastępuje `{token}`.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
PLAN=/tmp/fmi-stage14-plan.tsv
IDS=/tmp/fmi-stage14-ids.json
MANIFEST=/tmp/fmi-stage03-fixtures.json
MATRIX=/tmp/fmi-stage14-matrix.md
SITE=http://fillmein.local
source "$THEME/tests/bin/env.sh"
fmi_bootstrap >/dev/null 2>&1 || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k];} echo $m;' "$1" "$2"; }

C_ADMIN=$(json "$MANIFEST" cookies.admin); C_MANAGER=$(json "$MANIFEST" cookies.manager)
C_MEMBER=$(json "$MANIFEST" cookies.member); C_OUTSIDER=$(json "$MANIFEST" cookies.outsider)
C_SUB=$(json "$MANIFEST" cookies.subscriber)
TOKEN_A=$(json "$IDS" tokens.a); TOKEN_B=$(json "$IDS" tokens.b); TOKEN_R=$(json "$IDS" tokens.revoked)

WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmi14.XXXXXX")
chmod 700 "$WORK"
BODY=(-d 'base_version=0' -d 'value=x' -d 'label=x' -d 'note=x' -d 'name=x' -d 'type=text' -d 'alt=x')
FILE=(-F 'base_version=0')

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$@"; }
meta() { grep -o 'name="csrf-token" content="[^"]*"' "$1" | head -1 | sed 's/.*content="//;s/"$//'; }
session() { # session <plik jar> <url> [cookie]: strona, z której bierzemy token CSRF sesji
  local html="$WORK/html"
  if [ -n "${3:-}" ]; then curl -s -c "$1" -b "$1" -H "Cookie: $3" --max-time 25 -o "$html" "$2"; else curl -s -c "$1" -b "$1" --max-time 25 -o "$html" "$2"; fi
  meta "$html"
}

CSRF_GUEST=$(session "$WORK/guest" "$SITE/app/login")
CSRF_OUT=$(session "$WORK/outsider" "$SITE/app/profile" "$C_OUTSIDER")
CSRF_MEM=$(session "$WORK/member" "$SITE/app/profile" "$C_MEMBER")
CSRF_MGR=$(session "$WORK/manager" "$SITE/app/profile" "$C_MANAGER")
CSRF_ADM=$(session "$WORK/admin" "$SITE/app/profile" "$C_ADMIN")
CSRF_CA=$(session "$WORK/client-a" "$SITE/p/$TOKEN_A")
CSRF_CB=$(session "$WORK/client-b" "$SITE/p/$TOKEN_B")
[ -n "$CSRF_GUEST$CSRF_ADM" ] && [ -n "$CSRF_CA" ] && [ -n "$CSRF_CB" ] || { echo "BLOCKED: brak tokenu CSRF."; exit 2; }

as_user() { # as_user <cookie> <jar> <csrf> <metoda> <url>
  if [ "$4" = GET ]; then
    code -H "Cookie: $1" "$5"
  else
    code -c "$2" -b "$2" -H "Cookie: $1" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $3" -X POST "${BODY[@]}" "$5"
  fi
}
as_client() { # as_client <jar> <csrf> <metoda> <url>
  if [ "$3" = GET ]; then code "$4"; else code -c "$1" -b "$1" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $2" -X POST "${BODY[@]}" "$4"; fi
}

FAIL=0
TOTAL=0
count() { TOTAL=$((TOTAL + 1)); [[ " $2 " == *" $1 "* ]] || { FAIL=$((FAIL + 1)); echo "  FAIL  $ROUTE $METHOD: $3 — otrzymano $1, dozwolone: $2" >&2; }; }

{
  echo '| Trasa | Metoda | Ochrona | Gość | Bez dostępu | Spoza projektu | Przypisany bez roli | CSRF | IDOR | Obcy token | Właściciel |'
  echo '|---|---|---|---|---|---|---|---|---|---|---|'
} > "$MATRIX"

while IFS=$'\t' read -r ROUTE METHOD ZONE OWN FOREIGN; do
  URL="$SITE$OWN"; ANON=-; SUB=-; OUT=-; MEM=-; CSRF=-; IDOR=-; TOK=-; OK=-
  case "$ZONE" in
    guest)
      PROT='gość, throttling logowania'
      if [ "$METHOD" = GET ]; then
        ANON=$(code "$URL"); count "$ANON" '200' 'gość'
      else
        CSRF=$(code -H "Cookie: $C_ADMIN" -X POST "${BODY[@]}" "$URL"); count "$CSRF" '419' 'bez CSRF'
      fi
      ;;
    staff|users)
      PROT='sesja WP + fmi_access + Policy'
      [ "$ZONE" = users ] && PROT='sesja WP + can:manage-users'
      [[ "$ROUTE" == fmi.settings.* ]] && PROT='sesja WP + can:manage-integrations'
      if [ "$METHOD" = GET ]; then
        ANON=$(code "$URL")
      else
        ANON=$(code -c "$WORK/guest" -b "$WORK/guest" -H "X-CSRF-TOKEN: $CSRF_GUEST" -X POST "${BODY[@]}" "$URL")
        CSRF=$(code -H "Cookie: $C_ADMIN" -X POST "${BODY[@]}" "$URL"); count "$CSRF" '419' 'bez CSRF'
      fi
      count "$ANON" '302' 'gość'
      if [ "$METHOD" = GET ]; then SUB=$(code -H "Cookie: $C_SUB" "$URL"); count "$SUB" '403' 'bez fmi_access'; fi
      OUT=$(as_user "$C_OUTSIDER" "$WORK/outsider" "$CSRF_OUT" "$METHOD" "$URL")
      case "$OWN" in
        /app/projects/[0-9]*|/app/design-review/[0-9]*) count "$OUT" '404' 'spoza projektu' ;;
        /app/users*|/app/settings*) count "$OUT" '403' 'bez uprawnienia globalnego' ;;
        /app/projects/create|/app/projects) [ "$METHOD" = POST ] || [ "$OWN" = /app/projects/create ] && count "$OUT" '403' 'bez fmi_create_projects' || count "$OUT" '200' 'lista własnych' ;;
        # Powiadomienie należy do jednej osoby (administratora) — każda inna dostaje 404.
        /app/notifications/[0-9]*) count "$OUT" '404' 'cudze powiadomienie' ;;
        *) count "$OUT" '200 302 422' 'własny zasób' ;;
      esac
      case "$ROUTE" in
        fmi.projects.update|fmi.projects.status|fmi.projects.members|fmi.projects.destroy|fmi.projects.client-access.*|fmi.projects.mapping.update|fmi.projects.exports.store|fmi.projects.exports.regenerate|fmi.projects.site-schema.upload|fmi.projects.site-mapping.*)
          MEM=$(as_user "$C_MEMBER" "$WORK/member" "$CSRF_MEM" "$METHOD" "$URL"); count "$MEM" '403' 'przypisany bez capability' ;;
      esac
      if [ "$FOREIGN" != "-" ]; then
        IDOR=$(as_user "$C_ADMIN" "$WORK/admin" "$CSRF_ADM" "$METHOD" "$SITE$FOREIGN"); count "$IDOR" '403 404 422' 'obcy element'
      fi
      ;;
    review)
      # Link recenzji (etap 09 Design Review): token tylko przy wejściu, dalej sesja gościa. Zachowanie z sesją
      # i wszystkie możliwości linku: tests/review-links. Tu — odmowy bez sesji i dla tokenu innego zakresu.
      PROT='link recenzji → sesja gościa + throttling'
      if [ "$ROUTE" = fmi.review.enter ]; then
        ANON=$(code "$SITE/r/nieprawidlowy-token-recenzji-0000000000"); count "$ANON" '404' 'zły token'
        TOK=$(code "$URL"); count "$TOK" '404' 'token linku Treści'
      elif [ "$METHOD" = GET ]; then
        ANON=$(code "$URL"); count "$ANON" '403' 'bez sesji gościa'
      else
        # Świeża sesja bez gościa dla każdej trasy (wyjście regeneruje sesję): odmowa ma wynikać z braku gościa, nie z CSRF.
        CSRF_RG=$(session "$WORK/rg" "$SITE/review/unlock")
        ANON=$(code -c "$WORK/rg" -b "$WORK/rg" -H "X-CSRF-TOKEN: $CSRF_RG" -X POST "${BODY[@]}" "$URL")
        if [ "$ROUTE" = fmi.review.leave ]; then count "$ANON" '200' 'wyjście bez sesji'; else count "$ANON" '403' 'bez sesji gościa'; fi
        CSRF=$(code -X POST "${BODY[@]}" "$URL"); count "$CSRF" '419' 'bez CSRF'
        CSRF_RA=$(session "$WORK/ra" "$SITE/review/unlock" "$C_ADMIN")
        IDOR=$(code -H "Cookie: $C_ADMIN" -H "X-CSRF-TOKEN: $CSRF_RA" -b "$WORK/ra" -c "$WORK/ra" -X POST "${BODY[@]}" "$URL")
        [ "$ROUTE" = fmi.review.leave ] || count "$IDOR" '403' 'pracownik bez sesji gościa'
      fi
      ;;
    client)
      PROT='token + throttling'
      TOK_URL="${URL/$TOKEN_A/$TOKEN_B}"
      if [ "$METHOD" = GET ]; then
        ANON=$(code "$SITE/p/nieprawidlowy"); count "$ANON" '404' 'zły token'
        TOK=$(code "$SITE/p/$TOKEN_R"); count "$TOK" '404' 'odwołany token'
      else
        ANON=$(code -c "$WORK/client-a" -b "$WORK/client-a" -H "X-CSRF-TOKEN: $CSRF_CA" -X POST "${BODY[@]}" "${URL/$TOKEN_A/nieprawidlowy}"); count "$ANON" '404' 'zły token'
        CSRF=$(code -H 'Accept: application/json' -X POST "${BODY[@]}" "$URL"); count "$CSRF" '419' 'bez CSRF'
        TOK=$(as_client "$WORK/client-b" "$CSRF_CB" POST "$TOK_URL"); count "$TOK" '403 404 422' 'token innego projektu'
      fi
      if [ "$FOREIGN" != "-" ]; then
        IDOR=$(as_client "$WORK/client-a" "$CSRF_CA" "$METHOD" "$SITE$FOREIGN"); count "$IDOR" '403 404 422' 'obcy element'
      fi
      ;;
  esac
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$ROUTE" "$METHOD" "$PROT" "$ANON" "$SUB" "$OUT" "$MEM" "$CSRF" "$IDOR" "$TOK" "$OK" >> "$WORK/cells"
done < "$PLAN"

fmiwp eval-file "$THEME/tests/stage14/security.php" hash > "$WORK/hash-after"
if cmp -s "$WORK/hash-after" /tmp/fmi-stage14-hash; then echo "  PASS  Treść projektów A i B bez zmian po wszystkich odmowach"; TOTAL=$((TOTAL + 1)); else echo "  FAIL  Odmowy zmieniły dane"; TOTAL=$((TOTAL + 1)); FAIL=$((FAIL + 1)); fi

# Właściciel: najpierw klient, bo regeneracja linku w panelu unieważnia jego token.
: > "$WORK/owner"
while IFS=$'\t' read -r ROUTE METHOD ZONE OWN FOREIGN; do
  URL="$SITE$OWN"
  case "$ZONE:$METHOD" in
    client:GET) OK=$(code "$URL"); count "$OK" '200' 'klient' ;;
    client:POST) OK=$(as_client "$WORK/client-a" "$CSRF_CA" POST "$URL"); count "$OK" '200 403 409 422' 'klient: ścieżka istnieje' ;;
    *) continue ;;
  esac
  printf '%s\t%s\t%s\n' "$ROUTE" "$METHOD" "$OK" >> "$WORK/owner"
done < "$PLAN"

while IFS=$'\t' read -r ROUTE METHOD ZONE OWN FOREIGN; do
  URL="$SITE$OWN"
  case "$ZONE:$METHOD" in
    # Bez stanu sesji powrót z Figmy kończy się przekierowaniem i niczego nie zapisuje.
    users:GET) OK=$(code -H "Cookie: $C_ADMIN" "$URL"); if [ "$ROUTE" = fmi.settings.figma.callback ]; then count "$OK" '302' 'powrót bez stanu'; else count "$OK" '200' 'administrator'; fi ;;
    staff:GET) OK=$(code -H "Cookie: $C_ADMIN" "$URL"); if [ "$ROUTE" = fmi.notifications.open ]; then count "$OK" '302' 'właściciel: przejście do kontekstu'; else count "$OK" '200' 'administrator'; fi ;;
    # Pusta treść: poprawne dane utworzyłyby projekt spoza manifestu, którego sprzątanie by nie objęło.
    staff:POST) if [ "$ROUTE" = fmi.notifications.read ]; then OK=$(as_user "$C_ADMIN" "$WORK/admin" "$CSRF_ADM" POST "$URL"); count "$OK" '302' 'właściciel: oznaczenie'; elif [ "$ROUTE" = fmi.projects.store ]; then OK=$(code -c "$WORK/manager" -b "$WORK/manager" -H "Cookie: $C_MANAGER" -H 'Accept: application/json' -H "X-CSRF-TOKEN: $CSRF_MGR" -X POST "$URL"); else OK=$(as_user "$C_MANAGER" "$WORK/manager" "$CSRF_MGR" POST "$URL"); fi; count "$OK" '200 201 302 403 409 422' 'opiekun: ścieżka istnieje' ;;
    # Rozłączenie jako administrator skasowałoby prawdziwe połączenie: tylko odmowa dla opiekuna.
    users:POST) if [ "$ROUTE" = fmi.settings.figma.disconnect ]; then OK=$(as_user "$C_MANAGER" "$WORK/manager" "$CSRF_MGR" POST "$URL"); count "$OK" '403' 'opiekun bez uprawnienia'; else OK=$(as_user "$C_ADMIN" "$WORK/admin" "$CSRF_ADM" POST "$URL"); count "$OK" '200 201 302 403 422' 'administrator: ścieżka istnieje'; fi ;;
    guest:POST) OK=$(as_user "$C_MANAGER" "$WORK/manager" "$CSRF_MGR" POST "$URL"); count "$OK" '200 302 422 429' 'gość: ścieżka istnieje' ;;
    *) continue ;;
  esac
  printf '%s\t%s\t%s\n' "$ROUTE" "$METHOD" "$OK" >> "$WORK/owner"
done < "$PLAN"

php -r '
$owner = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) { [$r, $m, $c] = explode("\t", $line); $owner["$r $m"] = $c; }
$out = "";
foreach (file($argv[2], FILE_IGNORE_NEW_LINES) as $line) {
  $c = explode("\t", $line);
  $c[10] = $owner["$c[0] $c[1]"] ?? "-";
  $out .= "| " . implode(" | ", $c) . " |\n";
}
file_put_contents($argv[3], $out, FILE_APPEND);
' "$WORK/owner" "$WORK/cells" "$MATRIX"

ROUTES=$(wc -l < "$PLAN" | tr -d ' ')
HITS=$(grep -cF -e "$TOKEN_A" -e "$TOKEN_B" -e "$TOKEN_R" "$MATRIX")
[ "$HITS" = 0 ] && echo "  PASS  Macierz bez tokenów" || { echo "  FAIL  Token w macierzy"; FAIL=$((FAIL + 1)); }

rm -f "$WORK/rg" "$WORK/ra" "$WORK/guest" "$WORK/outsider" "$WORK/member" "$WORK/manager" "$WORK/admin" "$WORK/client-a" "$WORK/client-b" "$WORK/sub" "$WORK/html" "$WORK/v" "$WORK/cells" "$WORK/owner" "$WORK/hash-after"
rmdir "$WORK" 2>/dev/null

echo "ETAP 14 — macierz: $ROUTES tras, $((TOTAL - FAIL))/$TOTAL sprawdzeń PASS, $FAIL FAIL. Plik: $MATRIX"
[ "$FAIL" -eq 0 ]
