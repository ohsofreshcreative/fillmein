#!/usr/bin/env bash
#
# Build frontendu (`yarn build`) z PATH uzupełnionym o Homebrew (tests/bin/env.sh).
#
# Kody: kod yarn (0 OK), 2 BLOCKED (brak yarn w PATH).
#
# Użycie: bash tests/build/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd -P)"
source "$THEME/tests/bin/env.sh"

command -v yarn >/dev/null || { echo "BLOCKED: brak yarn w PATH."; exit 2; }

cd "$THEME" || exit 2
yarn build
exit $?
