# Design Review — etap 02: nowa nawigacja

Data: 2026-11-03. Zależność: etap 01 (`01-audyt.md`).

## Inwentaryzacja starych adresów
Moduł „Projekty” (dziś: Treści) żyje pod `/app/projects` i `/app/projects/{project}/…` — 61 tras `fmi.projects.*` (wg `acorn route:list` z etapu 01)
(lista, karta, podstrony, struktura, treść, podgląd, przegląd, eksport, mapowanie, aktywność, pliki, linki klientów).
Poza tym: `/app` (pulpit), `/app/users/*` (Gate `manage-users`), `/app/settings/figma*` (Gate `manage-integrations`),
`/app/profile`, `/app/design-system`, `/app/login|logout|password/set`, `/p/{token}` (klient).
Linki w e-mailach: tylko zaproszenia (`/app/password/set`). Linki klientów: `/p/{token}`.

## Decyzja: bezkolizyjne trasy
| Menu | Adres | Trasa | Dostęp |
|---|---|---|---|
| Pulpit | `/app` | `fmi.dashboard` | bez zmian |
| Projekty (Design Review) | **`/app/design-review`** (nowy) | `fmi.design-review.index` | `fmi.staff` + `ProjectPolicy::viewAny`, lista z `Project::visibleTo()` |
| Treści | `/app/projects/*` (bez zmian) | `fmi.projects.*` | bez zmian |
| Użytkownicy | `/app/users` | `fmi.users.*` | `can:manage-users`, w menu tylko gdy Gate pozwala |
| Figma | `/app/settings/figma` | `fmi.settings.figma*` | `can:manage-integrations`, w menu tylko gdy Gate pozwala |

Treści zostają pod `/app/projects`, bo przeniesienie i oddanie tego adresu modułowi Design Review sprawiłoby, że stare
linki (zakładki, historia, odnośniki w dzienniku) otworzyłyby inny zasób. Dzięki temu nie ma przekierowań — każdy stary
adres odpowiada temu samemu kontrolerowi i tej samej autoryzacji. Nazwa trasy `fmi.design-review.*` nie koliduje
z istniejącą `fmi.projects.review` (przegląd treści).

Widok Projekty pokazuje te same projekty co Treści (wspólny model) i komunikat, że prototyp, komentarze i akceptacje
pojawią się w kolejnych etapach; przycisk „Treści” prowadzi do starej karty projektu.

## Zmiany
- `routes/web.php` — trasa `GET /app/design-review`.
- `app/Http/Controllers/App/DesignReviewController.php` — nowy (lista projektów w zakresie użytkownika, bez zarchiwizowanych, paginacja 20).
- `resources/views/fillmein/design-review/index.blade.php` — nowy widok.
- `app/View/Composers/AppLayout.php` — menu: Pulpit, Projekty, Treści, Użytkownicy, Figma (aktywność wg `routeIs`).
- `resources/views/fillmein/partials/icon.blade.php` — ikona `review`.
- `resources/views/fillmein/projects/{index,show,create}.blade.php` — nagłówek i link powrotu „Treści”.
- `tests/nav/` (`run.sh`, `extra.php`) — nowy test; dopisany do `tests/all/run.sh`.
- `yarn build` — bez zmian w CSS/JS (te same pliki wynikowe).

Bez migracji i bez zmian danych.

## Testy
`bash tests/nav/run.sh` → **84 PASS, 0 FAIL**:
- 4 role (administrator, opiekun, członek, pracownik spoza projektu): pulpit, Projekty, stara lista `/app/projects`,
  stare szczegóły i treść podstrony (te same dane — nazwa projektu), menu i aktywna pozycja (`aria-current="page"`)
  na każdej z pięciu pozycji; Użytkownicy i Figma: widoczne i 200 dla administratora, ukryte i 403 dla pozostałych.
- Zakres: pracownik spoza projektu nie widzi projektu A w Projektach, a stare linki A dają 404; członek A nie widzi B.
- Gość: 6 adresów → 302 na `/app/login`; konto WP bez `fmi_access` → 403 (Projekty, Treści, karta projektu).
- Link klienta `/p/{token}` nadal otwiera ten sam projekt; nieistniejący projekt 404.
- HTML: przycisk menu mobilnego z etykietą, zamykanie Escape, `<nav aria-label="Główna nawigacja">`.

Odświeżenie i link bezpośredni: każdy adres jest zwykłym GET renderowanym po stronie serwera (testy otwierają go
bez wcześniejszej nawigacji). Historia przeglądarki: nawigacja to zwykłe linki, bez routingu po stronie klienta,
więc wstecz/dalej ładuje ten sam adres; sprawdzone pośrednio — osobnego testu w przeglądarce nie ma.

`bash tests/all/run.sh` → 19/19 zestawów kod 0; stage14: E2E 68/68, macierz 95 tras 428/428 (`fmi.design-review.index`: gość 302, bez dostępu 403, właściciel 200), audyt UI headless 32 widoki × 3 szerokości 96/96 (nowy widok PASS na 375, 768 i 1280 px).

## Ryzyka i ograniczenia
- Nazwa „Projekty” w menu wskazuje teraz Design Review — zespół przyzwyczajony do „Projekty = treści” musi wiedzieć, że treści są w „Treści”. Pulpit dalej linkuje do kart projektów w Treściach.
- Widok Projekty jest na tym etapie listą bez funkcji recenzji (etapy 04–10).

## Wycofanie
Usunąć trasę i kontroler `DesignReviewController`, widok `design-review/index.blade.php`, przywrócić wpis
`['label' => 'Projekty', 'route' => 'fmi.projects.index', …]` w `AppLayout`, etykiety „Projekty” w trzech widokach,
usunąć `tests/nav` i wpis `nav` z `tests/all/run.sh`. Dane nie są dotykane.
