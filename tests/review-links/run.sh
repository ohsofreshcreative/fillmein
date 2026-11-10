#!/usr/bin/env bash
#
# Design Review, etap 09 — linki recenzji dla klienta: wejście (token → sesja), zakres projektu, podmiana ID,
# wygasły / odwołany / bez komentarza / bez akceptacji, kod dostępu i limity prób, oddzielenie od Treści i panelu,
# odmowa Figmy przy działającym linku, brak tokenów w logach, sesjach i dzienniku. Dane zawsze sprzątane.
#
# Użycie: bash tests/review-links/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MANIFEST=/tmp/fmi-stage03-fixtures.json
IDS=/tmp/fmi-review-links.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/fmirl.XXXXXX"); chmod 700 "$WORK"
B="$WORK/b"
# Katalog strony w Local (…/fillmein): logs/nginx, logs/php.
LOGS="$(cd "$THEME/../../../../.." && pwd)"
LARAVEL_LOG="$THEME/../../cache/acorn/logs/laravel.log"
SESSIONS="$THEME/../../cache/acorn/framework/sessions"
touch "$WORK/start"
LOG_OFFSET=$( [ -f "$LARAVEL_LOG" ] && wc -c < "$LARAVEL_LOG" | tr -d ' ' || echo 0)

cleanup() { fmiwp eval-file "$THEME/tests/stage03/teardown.php"; rm -f "$IDS"; rm -rf "$WORK"; }
fmiwp eval-file "$THEME/tests/stage03/setup.php" || { echo "BLOCKED: dane testowe"; exit 2; }
trap cleanup EXIT
fmiwp eval-file "$THEME/tests/review-links/fixtures.php" || exit 2

json() { php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$m=$m[$k]??null;} echo is_scalar($m)?$m:json_encode($m);' "$1" "$2"; }
SITE=$(json "$MANIFEST" site); MANAGER=$(json "$MANIFEST" cookies.manager)
R=$(json "$IDS" r); F=$(json "$IDS" f); RS=$(json "$IDS" rs); FS=$(json "$IDS" fs); RT=$(json "$IDS" rt); FT=$(json "$IDS" ft)
R_NAME=$(json "$IDS" r_name); F_NAME=$(json "$IDS" f_name)
tok() { json "$IDS" "tokens.$1"; }
aid() { json "$IDS" "access.$1"; }
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1 ($3)"; PASS=$((PASS+1)); else echo "  FAIL  $1 — oczekiwano $2, otrzymano $3"; FAIL=$((FAIL+1)); fi; }
ok() { if [ "$2" -eq 0 ]; then echo "  PASS  $1"; PASS=$((PASS+1)); else echo "  FAIL  $1"; FAIL=$((FAIL+1)); fi; }
admin() { fmiwp eval-file "$THEME/tests/review-links/admin.php" "$@" 2>/dev/null | tail -1; }
uuid() { uuidgen | tr 'A-Z' 'a-z'; }
# Sesja gościa = własny słoik ciasteczek, bez ciasteczek WordPressa.
enter() { curl -s -o "$B" -D "$WORK/h-$1" -w '%{http_code}' --max-time 30 -b "$WORK/jar-$1" -c "$WORK/jar-$1" "$SITE/r/$2"; }
loc() { grep -i '^location:' "$WORK/h-$1" | tr -d '\r' | sed 's/^[Ll]ocation: //'; }
gget() { curl -s -o "$B" -D "$WORK/h-$1" -w '%{http_code}' --max-time 30 -b "$WORK/jar-$1" -c "$WORK/jar-$1" "$SITE$2"; }
gtoken() { grep -o 'name="csrf-token" content="[^"]*"' "$B" | head -1 | sed 's/.*content="//;s/"$//'; }
gpost() { local s="$1" t="$2" path="$3"; shift 3; curl -s -o "$B" -D "$WORK/h-$s" -w '%{http_code}' --max-time 30 -b "$WORK/jar-$s" -c "$WORK/jar-$s" -H "X-CSRF-TOKEN: $t" -H 'Accept: application/json' "$@" "$SITE$path"; }
header() { grep -i "^$2:" "$WORK/h-$1" | head -1 | tr -d '\r' | sed "s/^[^:]*: //"; }

admin clear-limits >/dev/null
echo "== Wejście: token → sesja =="
check "Pełny link: przekierowanie" 302 "$(enter g "$(tok full)")"
check "Przekierowanie na /review (bez tokenu w adresie)" "$SITE/review" "$(loc g)"
check "Referrer-Policy przy wejściu" "no-referrer" "$(header g Referrer-Policy)"
check "Strona recenzji" 200 "$(gget g /review)"
grep -qF "$R_NAME" "$B"; ok "Widać projekt linku" $?
! grep -qF "$F_NAME" "$B"; ok "Nie widać innego projektu" $?
! grep -qF "$(tok full)" "$B"; ok "Token nie trafia do HTML" $?
grep -q 'data-figma-notice' "$B" && grep -q 'nie daje dostępu do pliku w Figmie' "$B"; ok "Informacja o osobnym dostępie Figmy" $?
check "Referrer-Policy strony" "no-referrer" "$(header g Referrer-Policy)"
check "CSP frame-src" "frame-src https://embed.figma.com https://www.figma.com" "$(header g Content-Security-Policy)"
grep -q 'figma.com/proto/ZZtestNoAccess' "$B" && grep -q 'referrerpolicy="no-referrer"' "$B" && ! grep -q '<iframe' "$B"; ok "Link do prototypu w Figmie (bez Referer), bez ramki" $?
! grep -qiE 'figd_|access_token|refresh_token' "$B"; ok "Brak tokenów Figmy na stronie gościa" $?
check "Obraz ekranu projektu linku" 200 "$(gget g "/review/screens/$RS/image")"
check "Nieprawidłowy token → 404" 404 "$(enter x "abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG")"
check "Token w złym formacie → 404" 404 "$(enter x "%3Cscript%3E")"
check "Wygasły link → 404" 404 "$(enter x "$(tok expired)")"
check "Odwołany link → 404" 404 "$(enter x "$(tok revoked)")"
check "Link Treści na /r → 404 (inny zakres)" 404 "$(enter x "$(tok content)")"
check "Link recenzji na /p (edytor Treści) → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/p/$(tok full)")"
check "Link Treści nadal otwiera edytor Treści" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/p/$(tok content)")"
check "Bez sesji: /review → 403" 403 "$(gget nobody /review)"

echo "== Panel i inne projekty niedostępne z sesją gościa =="
for path in "/app/design-review/$R" "/app/design-review/$F" "/app/projects/$R" "/app" "/app/figma" "/wp-admin/"; do
  FINAL=$(curl -s -o /dev/null -L --max-redirs 5 -w '%{http_code} %{url_effective}' -b "$WORK/jar-g" "$SITE$path")
  if echo "$FINAL" | grep -qE '^200 .*(/app/login|wp-login)'; then ok "$path → logowanie ($FINAL)" 0; else ok "$path → logowanie (otrzymano $FINAL)" 1; fi
done

echo "== Podmiana identyfikatorów w żądaniach gościa =="
gget g /review >/dev/null; T=$(gtoken)
check "Komentarz bez podanej nazwy → 422" 422 "$(gpost g "$T" /review/comments -d body=Test -d context=Menu)"
grep -q 'Najpierw podaj' "$B"; ok "Komunikat: najpierw nazwa" $?
check "Nazwa gościa" 302 "$(curl -s -o /dev/null -D "$WORK/h-g" -w '%{http_code}' -b "$WORK/jar-g" -c "$WORK/jar-g" -H "X-CSRF-TOKEN: $T" --data-urlencode 'name=<b>Anna</b> Kowalska' "$SITE/review/name")"
check "Uwaga gościa (pole project_id ignorowane)" 201 "$(gpost g "$T" /review/comments -d 'body=Uwaga klienta' -d context=Stopka -d project_id="$F" -d request_id="$(uuid)")"
check "Wiadomość: typ klienta, link, nazwa deklarowana, projekt R, bez konta" "client|$(aid full)|Anna Kowalska|$R|-" "$(admin comment "$(aid full)")"
check "Pinezka na ekranie projektu linku" 201 "$(gpost g "$T" /review/comments -d 'body=Pinezka klienta' -d screen_id="$RS" -d x=0.5 -d y=0.25 -d request_id="$(uuid)")"
check "Ekran obcego projektu → 422" 422 "$(gpost g "$T" /review/comments -d body=X -d screen_id="$FS")"
check "Obraz ekranu obcego projektu → 404" 404 "$(gget g "/review/screens/$FS/image")"
gget g /review >/dev/null; T=$(gtoken)
check "Odpowiedź w wątku projektu linku" 201 "$(gpost g "$T" "/review/threads/$RT/replies" -d 'body=Odpowiedź klienta' -d request_id="$(uuid)")"
check "Odpowiedź w wątku obcego projektu → 404" 404 "$(gpost g "$T" "/review/threads/$FT/replies" -d body=X -d request_id="$(uuid)")"
check "Nieistniejący wątek → 404" 404 "$(gpost g "$T" "/review/threads/999999999/replies" -d body=X -d request_id="$(uuid)")"
check "Gość na trasie statusu panelu → odmowa (logowanie/403/404)" 1 "$(c=$(gpost g "$T" "/app/design-review/$R/threads/$RT/status" -d status=resolved -d version=0); [ "$c" = 302 ] || [ "$c" = 401 ] || [ "$c" = 403 ] || [ "$c" = 404 ] && echo 1 || echo "$c")"
check "Bez CSRF → 419" 419 "$(gpost g "" /review/comments -d body=X -d context=Y)"
check "XSS w nazwie i treści" 201 "$(gpost g "$T" /review/comments --data-urlencode 'body=<img src=x onerror=alert(1)>' -d context=XSS -d request_id="$(uuid)")"
gget g /review >/dev/null
grep -qF '&lt;img src=x onerror=alert(1)&gt;' "$B" && ! grep -q '<img src=x' "$B" && grep -q 'Anna Kowalska' "$B" && ! grep -q '<b>Anna' "$B"; ok "Treść escapowana, nazwa bez znaczników" $?
grep -q 'data-guest-mark' "$B"; ok "Nazwa oznaczona jako deklarowana (gość)" $?
curl -s -o "$B" -H "Cookie: $MANAGER" "$SITE/app/design-review/$R"
grep -q 'Anna Kowalska' "$B" && grep -q 'Gość · nazwa deklarowana' "$B"; ok "Panel: uwaga klienta z oznaczeniem „Gość · nazwa deklarowana”" $?

echo "== Link bez prawa komentarza / bez akceptacji =="
check "Podgląd: wejście" 302 "$(enter ro "$(tok readonly)")"
check "Podgląd: strona" 200 "$(gget ro /review)"
! grep -q 'data-comment-form' "$B" && ! grep -q 'data-comment-whole' "$B" && ! grep -q 'data-reply-form' "$B" && ! grep -q 'data-guest-name-form' "$B"; ok "Podgląd: bez formularzy uwag, odpowiedzi i nazwy" $?
TR=$(gtoken)
check "Podgląd: nowa uwaga → 403" 403 "$(gpost ro "$TR" /review/comments -d body=X -d context=Y)"
check "Podgląd: odpowiedź → 403" 403 "$(gpost ro "$TR" "/review/threads/$RT/replies" -d body=X -d request_id="$(uuid)")"
check "Link bez akceptacji: middleware approve → 403" 403 "$(admin approve "$(aid noapprove)")"
check "Link podglądu: middleware approve → 403" 403 "$(admin approve "$(aid readonly)")"
check "Link z akceptacją: middleware approve przepuszcza" 200 "$(admin approve "$(aid full)")"

echo "== Unieważnienie i wygaśnięcie w trakcie sesji =="
check "Link do odwołania: wejście" 302 "$(enter lv "$(tok live)")"
check "Przed odwołaniem: 200" 200 "$(gget lv /review)"; TL=$(gtoken)
check "Opiekun odwołuje link (panel)" 302 "$(curl -s -o "$B" -w '%{http_code}' -c "$WORK/jar-mg" -b "$WORK/jar-mg" -H "Cookie: $MANAGER" "$SITE/app/design-review/$R" >/dev/null; TMG=$(gtoken); curl -s -o /dev/null -w '%{http_code}' -b "$WORK/jar-mg" -c "$WORK/jar-mg" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $TMG" -X POST "$SITE/app/design-review/$R/links/$(aid live)/revoke")"
check "Po odwołaniu: strona → 403" 403 "$(gget lv /review)"
check "Po odwołaniu: zapis → 403" 403 "$(gpost lv "$TL" /review/comments -d body=X -d context=Y)"
check "Po odwołaniu: obraz ekranu → 403" 403 "$(gget lv "/review/screens/$RS/image")"
check "Po odwołaniu: ponowne wejście tokenem → 404" 404 "$(enter lv "$(tok live)")"
check "Link do wygaszenia: wejście" 302 "$(enter ex "$(tok expiring)")"
check "Przed wygaśnięciem: 200" 200 "$(gget ex /review)"
admin expire "$(aid expiring)" >/dev/null
check "Po wygaśnięciu: strona → 403" 403 "$(gget ex /review)"
check "Obcy link opiekuna w adresie odwołania → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' -b "$WORK/jar-mg" -c "$WORK/jar-mg" -H "Cookie: $MANAGER" -H "X-CSRF-TOKEN: $(curl -s -b "$WORK/jar-mg" -c "$WORK/jar-mg" -H "Cookie: $MANAGER" "$SITE/app/design-review/$R" | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//')" -X POST "$SITE/app/design-review/$R/links/$(aid foreign)/revoke")"

echo "== Kod dostępu i limit prób =="
check "Link z kodem: wejście" 302 "$(enter cd "$(tok code)")"
check "Przekierowanie na formularz kodu" "$SITE/review/unlock" "$(loc cd)"
check "Bez kodu: /review → 403" 403 "$(gget cd /review)"
check "Formularz kodu" 200 "$(gget cd /review/unlock)"; TC=$(gtoken)
for i in 1 2 3 4 5; do LAST=$(curl -s -o /dev/null -w '%{http_code}' -b "$WORK/jar-cd" -c "$WORK/jar-cd" -H "X-CSRF-TOKEN: $TC" -d code=zly-kod-$i "$SITE/review/unlock"); done
check "Błędny kod: powrót z błędem" 302 "$LAST"
check "6. próba (także poprawny kod) → 429" 429 "$(curl -s -o "$B" -w '%{http_code}' -b "$WORK/jar-cd" -c "$WORK/jar-cd" -H "X-CSRF-TOKEN: $TC" -d code=sekret-123 "$SITE/review/unlock")"
grep -q 'data-code-locked' "$B"; ok "Komunikat blokady" $?
check "Po blokadzie: /review → 403" 403 "$(gget cd /review)"
check "Drugi link z kodem: wejście" 302 "$(enter c2 "$(tok code2)")"
gget c2 /review/unlock >/dev/null; TC2=$(gtoken)
check "Poprawny kod → recenzja" 302 "$(curl -s -o /dev/null -D "$WORK/h-c2" -w '%{http_code}' -b "$WORK/jar-c2" -c "$WORK/jar-c2" -H "X-CSRF-TOKEN: $TC2" -d code=sekret-456 "$SITE/review/unlock")"
check "Po kodzie: /review" 200 "$(gget c2 /review)"

echo "== Przeglądarka (headless) =="
node "$THEME/tests/review-links/guest.mjs"; ok "Recenzja gościa w przeglądarce (w tym odmowa Figmy)" $?

echo "== Limit prób wejścia (IP) =="
LAST=""
for i in $(seq 1 30); do LAST=$(enter lim "abcdefghijklmnopqrstuvwxyz0123456789zz$i"); [ "$LAST" = 429 ] && break; done
check "Seria nieprawidłowych tokenów → 429" 429 "$LAST"

echo "== Tokeny nie trafiają do logów, sesji ani dziennika =="
FOUND=0
for name in full readonly noapprove code code2 expired revoked live expiring foreign content; do
  t=$(tok "$name")
  if tail -c +"$((LOG_OFFSET + 1))" "$LARAVEL_LOG" 2>/dev/null | grep -qF "$t"; then echo "  token $name w laravel.log"; FOUND=1; fi
  if grep -rqF "$t" "$LOGS/logs" 2>/dev/null; then echo "  token $name w logach serwera: $(grep -rlF "$t" "$LOGS/logs" 2>/dev/null | sed "s#$LOGS/##" | tr "\n" " ")"; FOUND=1; fi
  if find "$SESSIONS" -type f -newer "$WORK/start" -exec grep -lF "$t" {} + 2>/dev/null | grep -q .; then echo "  token $name w pliku sesji"; FOUND=1; fi
done
ok "Brak tokenów w laravel.log, logach nginx/PHP i plikach sesji zmienionych w teście" "$FOUND"
check "Brak tokenów i skrótów w dzienniku aktywności" 0 "$(fmiwp eval-file "$THEME/tests/review-links/logscan.php" 2>/dev/null | tail -1)"
echo "  INFO  logi serwera Local: $(ls "$LOGS/logs/nginx" 2>/dev/null | tr '\n' ' ')(brak access.log — nginx Local nie zapisuje adresów żądań)"

echo
echo "LINKI RECENZJI: $PASS PASS, $FAIL FAIL"
[ "$FAIL" -eq 0 ]
