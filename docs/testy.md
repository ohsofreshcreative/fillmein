# Uruchamianie testów

Testy działają na lokalnej stronie Local `http://fillmein.local`, która musi być uruchomiona.

```bash
bash tests/all/run.sh        # pełna regresja, tabela: zestaw, kod, czas; logi w $TMPDIR/fmi-all
bash tests/stage14/run.sh    # E2E, macierz bezpieczeństwa, sekrety, audyt UI
bash tests/lint/run.sh
yarn build
```

Skrypty uruchamiaj dokładnie w tej postaci, bez prefiksów zmiennych. Kody wyjścia: `0` PASS, `1` FAIL, `2` BLOCKED (środowisko), `3` LIVE NOT_RUN (brak sekretów). `tests/all/run.sh` traktuje `3` jako niepowodzenie.

## Środowisko: `tests/bin/env.sh`
Plik wykrywa socket MySQL strony, narzędzia i ścieżki. `fmi_bootstrap` zwraca `2`, gdy czegoś brakuje. Wtedy testów nie uruchamiaj na siłę, tylko usuń przyczynę.

## Sekrety
Testy LIVE czytają token i link Figmy z plików `.ai-workflow/secrets/figma-token` i `.ai-workflow/secrets/figma-live-url`. Katalog jest ignorowany przez git. Wartości nie trafiają do logów, a `env.sh` wypisuje tylko informację, czy sekret jest.

## Polecenia WP: `tests/bin/wp.sh`
Bezpośrednie `wp` jest zablokowane. Nakładka ma zamkniętą listę poleceń, a resztę odrzuca kodem `4`:

```bash
bash tests/bin/wp.sh acorn fmi:check
bash tests/bin/wp.sh acorn route:list
bash tests/bin/wp.sh acorn migrate:status
bash tests/bin/wp.sh db-backup <etykieta>
bash tests/bin/wp.sh acorn fmi:import <plik> [--apply --backup=<plik.sql>]
bash tests/bin/wp.sh acorn fmi:import:cleanup [--source=<źródło>] [--apply --backup=<plik.sql>]
```

`db-backup` wymaga `mysqldump` z Local w PATH. Migracje i import z `--apply` wymagają świeżego backupu. Nigdy nie używaj `migrate:fresh`, `migrate:reset` ani `db:wipe`.

## Audyt UI
`tests/stage14/run.sh` i `tests/ui-stage*/run.sh` używają Playwrighta i axe-core z `/tmp/fmi-ui-audit`, poza repozytorium. Katalog jest nietrwały. Po restarcie zainstaluj je ponownie:

```bash
mkdir -p /tmp/fmi-ui-audit && cd /tmp/fmi-ui-audit && npm install playwright axe-core && npx playwright install chromium
```

Brak narzędzi daje BLOCKED, a nie PASS.

## Kopia i próba odtworzenia

```bash
bash tests/restore/run.sh
```

Robi kopię (`db-backup restore-check`), uruchamia tymczasowy MySQL 8.4 z Local bez sieci (socket i dane w `$TMPDIR/fmi-restore.*`), importuje kopię i porównuje ją z bazą strony: liczebność tabel, `CHECKSUM TABLE` tabel fmi, klucze obce, osierocone wiersze. Baza strony jest tylko czytana. Uruchamiaj przed każdą migracją, gdy nic innego nie pisze do bazy. Katalog `fmi-restore.*` zawiera dane bazy, więc usuwasz go ręcznie.

## Dane testowe
Testy tworzą własne dane i sprzątają je po manifestach w `/tmp`. Każdy przebieg `tests/stage12/run.sh` i `tests/stage14/run.sh` zostawia na Pulpicie plik backupu `fillmein-pre-*.sql`. Te pliki usuwasz ręcznie.
