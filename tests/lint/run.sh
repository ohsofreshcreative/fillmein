#!/usr/bin/env bash
#
# Lint składni PHP (`php -l`): app/, config/, tests/ i functions.php (bez vendor/ i node_modules/).
#
# Kody: 0 brak błędów, 1 błędy składni, 2 BLOCKED (brak php w PATH).
#
# Użycie: bash tests/lint/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd -P)"
source "$THEME/tests/bin/env.sh"

command -v php >/dev/null || { echo "BLOCKED: brak php w PATH."; exit 2; }

cd "$THEME" || exit 2

DIRS=(app tests)
[ -d config ] && DIRS+=(config)

FILES=0
ERRORS=0

while IFS= read -r -d '' file; do
  FILES=$((FILES + 1))
  php -l "$file" >/dev/null 2>&1 || { ERRORS=$((ERRORS + 1)); php -l "$file" 2>&1 | grep -v '^$'; }
done < <({ find "${DIRS[@]}" -type d \( -name vendor -o -name node_modules \) -prune -o -type f -name '*.php' -print0; printf '%s\0' functions.php; })

echo "Sprawdzono plików PHP: $FILES, błędów składni: $ERRORS"
[ "$ERRORS" -eq 0 ]
