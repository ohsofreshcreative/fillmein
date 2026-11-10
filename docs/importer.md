# Importer Fill Me In

Przenosi eksport (`docs/export-format.md`) do WordPressa. Działa wyłącznie na stronie testowej `http://fillmein.local`.

## Użycie

```bash
bash tests/bin/wp.sh acorn fmi:import <export.zip|export.json>
bash tests/bin/wp.sh db-backup <etykieta>
bash tests/bin/wp.sh acorn fmi:import <plik> --apply --backup=<plik.sql>
bash tests/bin/wp.sh acorn fmi:import:cleanup [--source=<źródło>]
bash tests/bin/wp.sh acorn fmi:import:cleanup [--source=<źródło>] --apply --backup=<plik.sql>
```

Bez `--apply` polecenie niczego nie zapisuje i pokazuje plan. Przy `.json` pliki zdjęć są czytane z `export.zip` leżącego obok.

Kody wyjścia: 0 OK, 4 odmowa przed zapisem, 1 błąd w trakcie zapisu. Ponowne uruchomienie dokończy przerwany import.

## Zabezpieczenia

- **Tylko fillmein.local.** Polecenia odmawiają, gdy `home_url` lub `siteurl` jest inny. Brak trybu produkcyjnego to świadoma decyzja: zmiana wymaga osobnego etapu i zmiany `ImportEnvironment::SITE`.
- **Backup.** `--apply` wymaga pliku istniejącego, niepustego, młodszego niż 60 min, poza motywem i niebędącego dowiązaniem. Sprawdza to nakładka i samo polecenie.
- **Walidacja przed zapisem.** Schemat (`ExportValidator`), główna część `schema_version`, sumy SHA-256 i typy plików, istnienie typów wpisów.
- **Zakres zapisu.** Tylko wpisy i załączniki z meta `_fmi_import_source` oraz rekordy ACF `group_fmi_test_*` i `field_fmi_test_*`. Importer nie zmienia innych wpisów, użytkowników, opcji ani tabel `fmi_*`.
- **Sprzątanie** usuwa wyłącznie te elementy i odmawia, gdy wpis bez znacznika ma rodzica wśród usuwanych.

## Oznaczenia

| Meta | Znaczenie |
|---|---|
| `_fmi_import_source` | `<project_slug>:<UUID projektu>` |
| `_fmi_import_key` | `page:<UUID>`, `asset:<sha256>`, `group:<klucz>`, `field:<klucz>` |
| `_fmi_import_sha256` | suma pliku, deduplikacja mediów w obrębie źródła |
| `_fmi_import_hash` | skrót definicji grupy lub pola ACF |
| `_fmi_import_group` | grupa, do której należy pole ACF |

UUID projektu pełni rolę `export_id`: format nie ma identyfikatora eksportu, a nowy przy każdym eksporcie zmieniałby znacznik wszystkich wpisów. Dopasowanie używa wyłącznie tych meta.

## Mapowanie

- **Strony:** szkice, bo publikacja kasuje opcję `fresh_site`. Tytuł, slug, rodzic, kolejność i szablon z `mapping`.
- **Grupy ACF:** jedna na sekcję, `group_fmi_test_<12 znaków projektu>_<UUID sekcji>`, z lokalizacją `post_type`. Blok sekcji jest w opisie grupy.
- **Pole sekcji:** każda grupa ma jedno pole `group` o nazwie `s_<12 znaków UUID sekcji>`, a w nim pola eksportu. Nazwy są unikalne tylko w sekcji, więc bez opakowania pola z dwóch sekcji jednej strony pisałyby do tego samego klucza meta. Odczyt: `get_field('s_xxx', $post_id)['naglowek']`.
- **Pola:** `field_fmi_test_<UUID pola>`. Nazwa, typ i ustawienia z `acf`. Pola z `mapped: false` są pomijane z ostrzeżeniem.
- **Wartości:** `update_field` z kluczami pól, tylko dla sekcji z różnicą. Zdjęcia jako ID załączników.
- **Media:** biblioteka WP z ALT i podpisem. Ten sam plik w jednym źródle nie jest duplikowany.

Elementy usunięte z projektu nie znikają przy imporcie, z wyjątkiem pól ACF usuniętych z grupy.

## ACF

ACF Pro jest zainstalowany, ale nieaktywny, a aktywacja zmieniłaby opcję `active_plugins`. Importer ładuje wtyczkę tylko w swoim procesie, bez updatera. Grupy są więc niewidoczne w panelu WP, dopóki właściciel nie aktywuje wtyczki. Wartości leżą w zwykłych meta.

Moduł Site Health w ACF zapisuje przy ładowaniu zdarzenie do opcji `cron`, a przy zapisie grupy opcję `acf_site_health`. `ImportEnvironment::blockAcfWrites()` blokuje oba zapisy przed załadowaniem wtyczki, także w dry-run. Po aktualizacji ACF sprawdź, czy nie pojawiły się nowe zapisy: `tests/stage12/run.sh` porównuje skrót opcji i listę haków `cron`.
