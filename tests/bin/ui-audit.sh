#!/usr/bin/env bash
#
# Audyt UI: uruchamia `node audit.mjs` w katalogu /tmp/fmi-ui-audit-* (Playwright i axe-core
# zainstalowane w tym katalogu, nie w repozytorium). Inny katalog → odmowa (kod 4).
#
# Użycie: tests/bin/ui-audit.sh /tmp/fmi-ui-audit-<nazwa>

set -uo pipefail

deny() { echo "ODMOWA: $*" >&2; exit 4; }

[ "$#" -eq 1 ] || deny "podaj dokładnie jeden katalog /tmp/fmi-ui-audit-*."
[[ "$1" =~ ^/tmp/fmi-ui-audit-[A-Za-z0-9._-]+/?$ ]] && [[ "$1" != *..* ]] || deny "katalog musi pasować do /tmp/fmi-ui-audit-*."
[ -d "$1" ] && [ ! -L "${1%/}" ] || deny "katalog nie istnieje lub jest dowiązaniem symbolicznym."

DIR="$(cd "$1" && pwd -P)" || deny "nie można wejść do katalogu."
[[ "$DIR" =~ ^(/private)?/tmp/fmi-ui-audit-[A-Za-z0-9._-]+$ ]] || deny "katalog po rozwinięciu leży poza /tmp/fmi-ui-audit-*."
[ -f "$DIR/audit.mjs" ] || deny "brak $DIR/audit.mjs."

# Tylko PATH (node z Homebrew); audyt nie potrzebuje bazy, więc bez fmi_bootstrap.
source "$(cd "$(dirname "$0")" && pwd -P)/env.sh"

cd "$DIR" && node audit.mjs
