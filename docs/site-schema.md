# Schemat strony WordPress i dopasowanie

Fill Me In zbiera treści, zanim strona zostanie zakodowana, więc nazwy sekcji i pól pochodzą z warstw Figmy. Zakodowany motyw (Sage 11 + ACF Composer, sekcja = blok ACF) ma inne nazwy. Schemat strony łączy jedno z drugim: Fill Me In dopasowuje sekcje i pola do bloków motywu i eksportuje treści z prawdziwymi nazwami ACF. Wtyczka Fill Me In Sync importuje je bez mapowania po swojej stronie.

## Przebieg

1. Na zakodowanej stronie: `wp fmi-sync schema --output=schema.json` (wtyczka Fill Me In Sync; opcjonalnie `--post_type=page,materials`).
2. W projekcie: **Ustawienia projektu** albo **Treści → Mapowanie ACF** → „Wgraj schemat strony (WordPress)”. Wymaga uprawnienia eksportu (Administrator, Opiekun projektu) i modułu Treści.
3. Po wgraniu automat dopasowuje cały projekt. Wynik widać w **Mapowanie ACF**: lista podstron z licznikami (zielone / żółte / czerwone), a dla podstrony strona schematu, blok każdej sekcji i pole ACF każdego pola.
4. Błędne pary poprawia się listą rozwijaną i „Zapisz dopasowanie”. Zmiana staje się dopasowaniem ręcznym (`source: manual`). „Przywróć automat” przy podstronie, sekcji (razem z jej polami) albo polu usuwa ręczne dopasowanie.
5. **Eksport** tworzy paczkę jak dotąd, a `export.json` ma nazwy ze schematu (`docs/export-format.md` → „Nazwy ze schematu strony”).

Projekt bez schematu działa i eksportuje się jak wcześniej (nazwy z Figmy, formularz ręcznych nazw ACF).

## Format wejścia

`format: fmi-site-schema`, `schema_version: 1.x`, `site` (`url`, `name`, `theme`), `post_types`, `blocks[]` (`name`, `title`, `fields[]`) i `pages[]` (`id`, `post_type`, `slug`, `path`, `title`, `template`, `blocks[]` z `position`, `name`, `occurrence`, `acf`). Pola: `key`, `name`, `label`, `type`, opcjonalnie `required`, `instructions`, `choices`, `min`, `max`, `return_format`, `sub_fields` (grupa, repeater), `layouts` (flexible content). Plik innego formatu jest odrzucany z komunikatem. Limit 10 MB.

Schemat jest zapisywany przy projekcie (`fmi_site_schemas`: ostatnia wersja, data wgrania, `site.url`). Ponowne wgranie zastępuje schemat, zachowuje ręczne poprawki i liczy automat od nowa. Ręczne dopasowanie wskazujące blok lub pole, którego nie ma w nowym schemacie, jest **nieaktualne** (czerwone, ostrzeżenie na górze ekranu) i nie trafia do eksportu.

## Algorytm (`App\Services\SiteMatcher`)

Klasa bez bazy danych: wejście to struktura projektu i schemat (`App\Support\SiteSchemaIndex`), wyjście — propozycje z `confidence` 0–1. Ręczne dopasowania są wejściem stałym: automat ich nie zmienia i nie zajmuje ich celów. Testy: `tests/site-schema/matcher.php`.

Teksty są normalizowane (małe litery, bez polskich znaków, wszystko poza `[a-z0-9]` → spacja) i porównywane współczynnikiem Dice'a na bigramach.

- **Strona ↔ strona schematu:** ten sam `slug` i `post_type` (pewność 1.0), w przeciwnym razie największe podobieństwo nazwy do `title`, jeśli ≥ 0.7. Brak pary = nowa strona.
- **Sekcja ↔ blok (strona ma parę):** widoczne sekcje wg pozycji i bloki ACF strony wg pozycji są wyrównywane jak sekwencje (Needleman-Wunsch bez kary za przerwę). Wynik pary: 0.45 × podobieństwo struktury pól + 0.4 × zgodność pozycji + 0.15 × podobieństwo nazwy sekcji do nazwy lub tytułu bloku. Dodatkowa albo brakująca sekcja nie przesuwa pozostałych par. Powtórzony blok dostaje `occurrence`.
- **Sekcja ↔ blok (nowa strona albo sekcja, której zabrakło bloku na stronie):** blok motywu o najwyższym wyniku 0.8 × struktura + 0.2 × nazwa, jeśli ≥ 0.5. Wtyczka doda go jako nowy blok.
- **Pole ↔ pole ACF:** wyrównanie po kolei na każdym poziomie (pola sekcji ↔ pola korzenia bloku, dzieci repeatera ↔ `sub_fields` sparowanego repeatera). Wynik: 0.5 × zgodność typu + 0.3 × zgodność pozycji + 0.2 × podobieństwo etykiety, klucza i tekstu z Figmy do `label` i `name` (+0.1 przy podobieństwie ≥ 0.8); repeater dodatkowo ocenia dopasowanie swoich dzieci. Grupy są przezroczyste po obu stronach (liście wyrównywane przez grupy); grupa z Figmy dostaje grupę ACF, w której leży większość jej pól. Repeatery zachowują zagnieżdżenie. Każde pole ACF najwyżej raz w sekcji. Pola wewnątrz `flexible_content` nie są dopasowywane (wtyczka ich nie importuje).

Zgodność typów (Fill Me In → ACF): tekst (`text`, `textarea`, `wysiwyg`) → `text`, `textarea`, `wysiwyg`, `email`, `url`, `number`, `oembed`; `image` → `image`, `file`; `gallery` → `gallery`; `link` → `link` (ewentualnie `url` z niższą pewnością — wtyczka ostrzeże wtedy o formacie wartości); `repeater` → `repeater`; `group` → `group`.

Progi: ≥ 0.8 zielone, 0.5–0.8 żółte (do sprawdzenia), < 0.5 lub brak pary czerwone. Ręczne — zielone.

## Ostrzeżenia na ekranie

- pola z treścią klienta bez pary (poza świadomym „nie importuj”),
- wymagane pola ACF (`required`) bez źródła — w korzeniu bloku i w repeaterach, które mają parę,
- dopasowania nieaktualne po zmianie schematu.

## Dane

`fmi_site_mappings`: `mappable_type` (`page`, `section`, `field`), `mappable_id`, `target` (ID wpisu, nazwa bloku albo klucz pola ACF; `null` = bez pary / nie importuj), `position` (pozycja bloku na stronie schematu), `confidence`, `source` (`auto` | `manual`). Automat usuwa i wstawia na nowo tylko wiersze `auto`. Logika: `App\Services\SiteMappingService` (wgrywanie, automat, zmiany ręczne, `resolve()` dla ekranu i eksportu).

Testy: `bash tests/site-schema/run.sh` (algorytm, serwis i eksport ze sprawdzeniem reguł importu wtyczki, HTTP z uprawnieniami).
