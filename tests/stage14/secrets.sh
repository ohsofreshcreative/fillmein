#!/usr/bin/env bash
#
# Etap 14 — skan plików śledzonych przez git. Wypisuje tylko ścieżkę i numer linii, nigdy wartości.
# Pliki `key` i `key.pub` to znany incydent (AGENTS.md → Bezpieczeństwo): nie wolno ich odczytywać, więc są
# zgłaszane po nazwie, bez otwierania. Kod 1: nowe trafienie. Znany incydent nie zmienia kodu, ale zawsze
# jest wypisany jako otwarty, a raport musi go wymienić.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$THEME" || exit 2

FAILED=0
# Atrapa `figd_FMI_TEST…` z tests/stage04/_fake.php nie jest tokenem: wzorzec wyklucza tylko ten prefiks.
TOKENS='figd_([A-EG-Za-z0-9_-]|F[A-LN-Za-z0-9_-]|FM[A-HJ-Za-z0-9_-])[A-Za-z0-9_-]{14,}'
KEYS='-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
PASSWORDS="(password|passwd|pwd|api_key|apikey|secret|token)['\"]?[[:space:]]*(=|=>|:)[[:space:]]*['\"][^'\"\$[:space:]]{12,}['\"]"

# Cały kod aplikacji jest jeszcze nieśledzony (stan przed pierwszym commitem aplikacji), więc skan samych
# `git ls-files` pominąłby to, co trafi do następnego commitu. Dlatego: pliki śledzone i nieśledzone nieignorowane
# z dysku oraz zawartość HEAD, czyli to, co już jest w historii.
FILES=()
while IFS= read -r -d '' file; do
  case "$file" in
    key|key.pub) echo "  ZNANY INCYDENT  $file (nie otwierano)" ;;
    # scan-secrets.php i ten plik zawierają same wzorce, a nie wartości.
    yarn.lock|pnpm-lock.yaml|composer.lock|public/build/*|tests/stage14/secrets.sh|tests/step00/scan-secrets.php) ;;
    *) [ -f "$file" ] && [ ! -L "$file" ] && FILES+=("$file") ;;
  esac
done < <(git ls-files -z --cached --others --exclude-standard | sort -zu)

echo "  Pliki na dysku w skanie: ${#FILES[@]}; pliki w HEAD: $(git ls-tree -r --name-only HEAD | wc -l | tr -d ' ')"

scan() { # scan <opis> <wzorzec>
  local hits history
  hits=$(printf '%s\0' "${FILES[@]}" | xargs -0 grep -InE -- "$2" 2>/dev/null | cut -d: -f1,2)
  history=$(git grep -InE -- "$2" HEAD -- . ':!key' ':!key.pub' ':!yarn.lock' ':!pnpm-lock.yaml' ':!public/build' 2>/dev/null | cut -d: -f2,3 | sed 's/^/HEAD:/')
  if [ -n "$hits$history" ]; then
    echo "  FAIL  $1:"
    printf '%s\n%s\n' "$hits" "$history" | sed '/^$/d;s/^/        /'
    FAILED=1
  else
    echo "  PASS  $1: 0 trafień"
  fi
}

scan "Tokeny Figma" "$TOKENS"
scan "Klucze prywatne" "$KEYS"
scan "Hasła i klucze w kodzie" "$PASSWORDS"

for path in .ai-workflow/secrets/figma-token .ai-workflow/secrets/figma-live-url .claude/settings.local.json; do
  if git check-ignore -q "$path"; then
    echo "  PASS  Ignorowany: $path"
  else
    echo "  FAIL  Nie jest ignorowany: $path"
    FAILED=1
  fi
done

SECRET_TRACKED=$(git ls-files -- .ai-workflow/secrets .claude/settings.local.json | wc -l | tr -d ' ')
if [ "$SECRET_TRACKED" = 0 ]; then
  echo "  PASS  Sekrety poza indeksem"
else
  echo "  FAIL  Sekrety w indeksie: $SECRET_TRACKED"
  FAILED=1
fi

exit "$FAILED"
