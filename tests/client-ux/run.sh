#!/usr/bin/env bash
#
# Edytor klienta: nazwy czytelne dla klienta i podglądy sekcji (zakres projektu, sekcje ukryte). Transakcja wycofywana.
#
# Użycie: bash tests/client-ux/run.sh

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

fmiwp eval-file "$THEME/tests/client-ux/logic.php"
