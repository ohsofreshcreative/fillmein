# Format eksportu Fill Me In

Eksport tworzy się w projekcie: **Treści → Eksport**. Obejmuje cały projekt albo wybrane podstrony. Wynik to paczka ZIP:

```
export.zip
├── content.json                 # uniwersalny format treści (fmi-content 2.x) — dla przyszłej wtyczki WordPress
├── export.json                  # profil ACF (fmi-export 1.x) — dla obecnego importera `fmi:import`
└── media/
    ├── 3f8a…c1.png              # <sha256>.<rozszerzenie>
    └── 9b02…7e.jpg
```

- `content.json` nie zależy od WordPressa, Sage ani nazw bloków ACF. Mapowanie na ACF ma zrobić przyszła wtyczka.
- `export.json` to dotychczasowy format z mapowaniem ACF (zakładka **Mapowanie ACF**). Zostaje, bo czyta go importer z etapu 12.
- Oba dokumenty wskazują te same pliki w `media/`. Nazwa pliku to jego SHA-256, więc importer sprawdzi integralność i pominie pliki już zaimportowane.

Każdy dokument można też pobrać osobno z historii eksportów. Schematy: `resources/schemas/fmi-content.schema.json` i `resources/schemas/fmi-export.schema.json`. Przykład: [`docs/examples/content.example.json`](examples/content.example.json).

## Wersjonowanie

`schema_version` ma postać `GŁÓWNA.POBOCZNA`. Obecnie `content.json` ma `2.0` (`ContentExportBuilder::SCHEMA_VERSION`), a `export.json` ma `1.2` (`ExportService::SCHEMA_VERSION`).

- Zmiana niezgodna wstecz (usunięcie lub zmiana znaczenia klucza, nowa wymagana wartość) podbija część główną. Czytnik danej wersji głównej odrzuca wtedy plik.
- Zmiana zgodna (nowy opcjonalny klucz) podbija część poboczną. Klucz trzeba dopisać do schematu, bo schemat zabrania nieznanych kluczy.

Czytnik rozpoznaje dokument po `format` (`fmi-content`) i głównej części `schema_version`.

## `content.json` — format uniwersalny (2.0)

### Klucze główne

| Klucz | Zawartość |
|---|---|
| `format` | stałe `fmi-content` |
| `schema_version` | `2.0` |
| `generator` | `{"name": "fill-me-in"}` |
| `exported_at` | czas UTC (`2026-11-05T12:00:00Z`) |
| `scope` | `type`: `project` (cały projekt) albo `pages` (wybrane); `page_ids`: UUID podstron w paczce |
| `project` | `id` (UUID), `name`, `slug`, `figma` (`{"file_key"}` albo `null`) |
| `summary` | liczby: `pages`, `sections`, `fields`, `filled`, `empty`, `cleared`, `media`, `media_links`, `missing` |
| `pages` | drzewo treści: podstrony → sekcje → pola |
| `media` | pliki użyte w polach |
| `missing` | brakujące treści |

Kolejność list to kolejność z aplikacji: podstrony w porządku drzewa, a sekcje, pola i wiersze wg pozycji. Identyfikatory to UUID, stałe między eksportami. Ten sam stan treści daje ten sam dokument, z wyjątkiem `exported_at`.

### Podstrona

`id`, `parent_id` (UUID rodzica albo `null`), `name`, `slug`, `position`, `figma`, `sections`.

Gdy rodzic nie trafił do paczki (jest w archiwum albo nie został wybrany), podstrona ma `parent_id: null`.

### Sekcja

`id`, `name`, `position`, `hidden`, `figma`, `client_note`, `fields`.

- `hidden: true` — sekcja ukryta przed klientem, ale nadal eksportowana.
- `client_note` — „Dodatkowe uwagi” klienta pod sekcją, albo `null`.

### Powiązania z Figmą

- Podstrona i sekcja: `figma` to `{"node_id", "node_name"}` albo `null`.
- Pole: `figma` to `{"node_id", "text"}` albo `null`. `text` to tekst warstwy z makiety.
- Projekt: tylko `file_key`.

Adresów Figmy i tokenów eksport nie zawiera.

### Pole

Wspólne klucze każdego pola:

| Klucz | Znaczenie |
|---|---|
| `id` | UUID definicji pola |
| `key` | nazwa techniczna (snake_case, unikalna w rodzicu) |
| `label` | etykieta widoczna dla klienta |
| `type` | `text`, `textarea`, `wysiwyg`, `link`, `image`, `gallery`, `group`, `repeater` |
| `required`, `hidden` | wymagane / ukryte przed klientem |
| `instructions` | podpowiedź dla klienta albo `null` |
| `position` | pozycja w rodzicu |
| `settings` | ustawienia pola, np. `max_length`, `min_items`, `max_items`, `require_alt` |
| `figma` | powiązanie z warstwą |
| `state` | stan treści, opis niżej |

Wartość zależy od typu:

| `type` | Klucze z treścią |
|---|---|
| `text`, `textarea`, `wysiwyg` | `value`: tekst albo `null` (`wysiwyg` to HTML z edytora) |
| `link` | `value`: `{"label", "url"}` albo `null` — przyciski i linki |
| `image` | `value`: `{"media_id", "path"}` albo `null`; `source_link`: link podany zamiast pliku albo `null` |
| `gallery` | `value`: lista `{"media_id", "path"}` w kolejności; `source_link` jak wyżej |
| `group` | `fields`: pola w grupie (grupa nie ma `value`) |
| `repeater` | `rows`: wiersze `{"id", "position", "fields"}`; repeater może być zagnieżdżony |

Pola w wierszu mają tę samą definicję (`id`) w każdym wierszu. Unikalny jest `id` wiersza.

### Stan pola (`state`)

| `state` | Znaczenie |
|---|---|
| `filled` | pole ma treść |
| `empty` | pole nigdy nie było uzupełnione |
| `cleared` | pole miało treść, ale ktoś ją celowo usunął (widać to w historii zmian) |

- Dla zdjęć i galerii treścią jest plik albo `source_link`.
- Dla repeatera treścią jest co najmniej jeden wiersz.
- Stan grupy wynika z pól w środku: `filled`, jeśli którekolwiek ma treść.
- Importer może np. wyczyścić pole przy `cleared`, a przy `empty` zostawić wartość domyślną.

### `media`

`id`, `path` (`media/<sha256>.<rozszerzenie>`, względna w paczce), `file_name` (nazwa od klienta), `mime`, `size`, `width`, `height`, `sha256`, `alt`, `caption`.

Odwołanie w polu (`media_id`, `path`) zawsze wskazuje wpis z tej listy.

### `missing`

Każdy wpis to `page_id`, `page`, `section_id`, `section`, `field_id`, `field`, opcjonalnie `row_id` oraz `reason`:

- `empty` — wymagane pole bez treści,
- `too_few` — mniej elementów niż `min_items`,
- `missing_alt` — zdjęcie bez tekstu alternatywnego, gdy pole go wymaga.

### Przykład (skrót)

```json
{
  "format": "fmi-content",
  "schema_version": "2.0",
  "scope": { "type": "project", "page_ids": ["…"] },
  "project": { "id": "…", "name": "Przykładowy projekt", "slug": "przykladowy-projekt", "figma": { "file_key": "AbCdEfGh12345678" } },
  "pages": [{
    "id": "…", "parent_id": null, "name": "Strona główna", "slug": "strona-glowna", "position": 0,
    "figma": { "node_id": "12:345", "node_name": "Home — desktop" },
    "sections": [{
      "id": "…", "name": "Główna sekcja", "position": 0, "hidden": false,
      "figma": { "node_id": "12:400", "node_name": "Hero" }, "client_note": null,
      "fields": [
        { "id": "…", "key": "naglowek", "label": "Nagłówek", "type": "text", "state": "filled", "value": "Tytuł strony", "…": "…" },
        { "id": "…", "key": "przycisk", "label": "Przycisk", "type": "link", "state": "filled", "value": { "label": "Kontakt", "url": "https://example.com/kontakt" } },
        { "id": "…", "key": "zdjecie", "label": "Zdjęcie", "type": "image", "state": "filled", "value": { "media_id": "…", "path": "media/3f8a….png" }, "source_link": null },
        { "id": "…", "key": "lista", "label": "Lista", "type": "repeater", "state": "filled", "rows": [{ "id": "…", "position": 0, "fields": ["…"] }] }
      ]
    }]
  }],
  "media": [{ "id": "…", "path": "media/3f8a….png", "file_name": "zdjecie.png", "mime": "image/png", "size": 1234, "width": 320, "height": 200, "sha256": "3f8a…", "alt": null, "caption": null }],
  "missing": [{ "page_id": "…", "page": "Strona główna", "section_id": "…", "section": "Główna sekcja", "field_id": "…", "field": "Podtytuł", "reason": "empty" }]
}
```

Pełny, poprawny wobec schematu przykład: `docs/examples/content.example.json`. Test `tests/stage11/content.php` sprawdza go przy każdym przebiegu.

## Historia eksportów

Każdy eksport zapisuje się w `fmi_exports`. Historia pokazuje:
- datę, autora i status,
- zakres: „Cały projekt” albo nazwy podstron z chwili eksportu,
- wersje formatów, rozmiar paczki i liczby z podsumowania.

**Wygeneruj ponownie** tworzy nowy eksport z tym samym zakresem, na bieżącej treści. Podstrony usunięte w międzyczasie są pomijane.

Eksport tylko czyta dane: nie zmienia treści, wersji pól ani statusów klienta i projektu. Dostęp mają osoby przypisane do projektu z uprawnieniem `fmi_export`, czyli Administrator, Opiekun projektu albo pracownik z nadanym uprawnieniem.

## Duże projekty

- Pliki trafiają do ZIP bez ponownej kompresji (zdjęcia są już skompresowane).
- `ZipArchive::addFile` czyta pliki z dysku dopiero przy zamykaniu paczki, więc pamięć nie rośnie z liczbą zdjęć.
- Rekordy plików są pobierane partiami po 500.
- Limit czasu generowania jest podnoszony do 300 s, jeśli serwer na to pozwala.

## `export.json` — profil ACF (1.2)

Format importera `fmi:import` (`docs/importer.md`) i wtyczki Fill Me In Sync (`wp fmi-sync fmi paczka.zip`).

- 1.1 względem 1.0: pliki leżą w `media/` zamiast `assets/`.
- 1.2 względem 1.1 (zgodnie wstecz, same opcjonalne klucze): `schema`, `sections[].mapping.occurrence`, `fields[].acf.key`, `fields[].acf.transparent`, `missing.unmapped[].label` i `has_content`. Nazwy ACF mogą mieć wielkie litery i myślniki, bo pochodzą z motywu.

### Nazwy ze schematu strony

Bez schematu nazwy bloków i pól wynikają z nazw warstw Figmy (sekcja „Frame 347” → `acf/frame-347`). Gdy w projekcie jest wgrany schemat zakodowanej strony (**Mapowanie ACF**, opis: [`docs/site-schema.md`](site-schema.md)), `export.json` bierze wartości z dopasowania:

- `schema`: `site_url`, `uploaded_at`, `schema_version` — z którym schematem zrobiono mapowanie. Brak klucza = nazwy z Figmy.
- `pages[].mapping`: `post_type`, `slug`, `template` strony ze schematu. Strona bez pary dostaje swój slug — wtyczka ją utworzy.
- `sections[].mapping`: `acf_block` i `occurrence` — który z kolei to blok tego typu wśród widocznych sekcji podstrony (tak łączy je wtyczka). Sekcja bez pary ma nazwę z Figmy i trafia do importu jako nieznany blok.
- `fields[].acf`: `name`, `type`, `key` i `path` pola ACF ze schematu, `mapped: true`. Ścieżka od korzenia bloku, segmenty rozdzielone `.`, wiersze repeatera `nazwa.*.`, np. `g_top.header`, `r_cards.*.title`, `r_list.*.r_cards.*.icon`. Ścieżka każdego pola w repeaterze zaczyna się od `ścieżka_repeatera.*.`. Grupy z Figmy bez odpowiednika w ACF nie występują w ścieżkach.
- Pole bez pary (także ręczne „nie importuj” i dopasowanie nieaktualne): `acf.mapped: false`, `acf.key: null`, nazwa i ścieżka domyślne, wpis w `missing.unmapped` z `label` i `has_content`.
- Grupa z Figmy bez odpowiednika: `acf.mapped: false`, `acf.transparent: true`, bez wpisu w `missing.unmapped` (jej pola mają własne pary).

`values`, `assets`, `content.json` i `media/` nie zmieniają się.

### Klucze główne `export.json`

| Klucz | Zawartość |
|---|---|
| `schema_version` | wersja formatu |
| `generator` | stałe `name: fill-me-in`, `format: fmi-export` |
| `exported_at` | czas UTC, jedyna wartość zmienna między eksportami tego samego stanu |
| `project` | `id` (UUID), `name`, `slug` wyliczony z nazwy |
| `pages` | podstrony w kolejności drzewa |
| `sections` | sekcje w kolejności podstron i pozycji |
| `fields` | definicje pól z mapowaniem ACF |
| `values` | treść |
| `assets` | pliki użyte w wartościach, wg UUID |
| `missing` | raport braków |

Wszystkie odwołania używają UUID. Eksport obejmuje aktywne elementy: bez archiwum, także pola ukryte przed klientem (`hidden: true`).

#### `pages`

`id`, `parent_id`, `name`, `slug`, `position`, `mapping`. W `mapping`: `post_type` (domyślnie `page`), `slug`, `template`.

#### `sections`

`id`, `page_id`, `name`, `position`, `hidden`, `mapping.acf_block`, opcjonalnie `mapping.occurrence` (ze schematem strony). Sekcja to blok ACF, domyślnie `acf/<slug nazwy>`, z sufiksem liczbowym przy powtórzeniu na podstronie.

#### `fields`

`id`, `section_id`, `parent_id`, `key`, `label`, `type`, `required`, `hidden`, `instructions`, `position`, `settings`, `acf`.

W `acf`: `name` (unikalna w rodzicu), `type`, `path`, `mapped`, `settings`, ze schematem strony także `key` (klucz pola ACF albo `null`) i przy grupie bez odpowiednika `transparent: true`. Ścieżka łączy nazwy kropkami, a wiersze repeatera oznacza `*`, np. `g_values.r_values.*.header`.

#### `values`

Lista obiektów `field_id`, `field_type`, `value`:

| `field_type` | `value` |
|---|---|
| `text`, `textarea`, `wysiwyg` | tekst lub `null` |
| `link` | `{"label", "url"}` lub `null` |
| `image` | UUID pliku lub `null` |
| `gallery` | lista UUID plików w kolejności |
| `repeater` | lista wierszy `{"id", "position", "values"}` |

Grupa nie ma własnej wartości: jej pola są na tym samym poziomie co grupa. `values` w wierszu mają ten sam kształt, a repeater może być zagnieżdżony.

#### `assets`

`id`, `sha256`, `mime`, `width`, `height`, `size`, `alt`, `caption`, `file_name`, `path` (względna w paczce: `media/…`; eksporty 1.0 miały `assets/…`, schemat przyjmuje oba).

#### `missing`

- `required_empty`: wymagane bez treści. `reason`: `empty`, `too_few` (poniżej `min_items`), `missing_alt`.
- `unmapped`: pola bez poprawnego mapowania, z `message`; ze schematem strony także `label` i `has_content` (czy klient wpisał treść).

Każdy wpis wskazuje `page_id`, `page`, `section_id`, `section`, `field_id`, `field`, a dla pola w repeaterze `row_id`.

### Mapowanie FieldType → ACF

| Typ | ACF | Nazwa | Dozwolone nadpisania typu |
|---|---|---|---|
| `text` | `text` | `<klucz>` | `textarea`, `wysiwyg` |
| `textarea` | `textarea` | `<klucz>` | `text`, `wysiwyg` |
| `wysiwyg` | `wysiwyg` | `<klucz>` | `textarea` |
| `link` | `link` (`return_format: array`) | `<klucz>` | brak |
| `image` | `image` (`return_format: array`) | `<klucz>` | brak |
| `gallery` | `gallery` (`return_format: array`) | `<klucz>` | brak |
| `group` | `group` | `g_<klucz>` | brak |
| `repeater` | `repeater` | `r_<klucz>` | brak |

Nowy `FieldType` wymaga decyzji w `AcfMappingService::definition()`. Typ nieobsługiwany ma `supported: false`, trafia do `missing.unmapped` z komunikatem i ma `acf.mapped: false`.

## Bezpieczeństwo

Eksport buduje każdy klucz jawnie. Nie zawiera tokenów, haseł, autorów zmian, e-maili z kont ani ścieżek serwera. Treść wpisana przez klienta jest eksportowana bez zmian, więc może zawierać np. adres e-mail podany w polu.
