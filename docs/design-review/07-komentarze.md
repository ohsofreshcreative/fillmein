# Design Review — etap 07: komentarze przypięte do projektu (2026-11-03)

## Decyzje

- **Potwierdzona powierzchnia = obraz frame'u z Figmy, nie osadzony prototyp.** Embed Kit nie przekazuje nam
  zdarzeń (etap 05: origin niezarejestrowany), więc aplikacja nie zna ekranu ani geometrii prototypu.
  Pinezki stawiamy wyłącznie na **ekranach rewizji** (`fmi_review_screens`): frame'ach pobranych przez istniejące
  połączenie OAuth (`FigmaFrameService::fetchTarget`, obraz na prywatnym dysku `fmi`).
- **Układ odniesienia `frame_bounds_v1`:** `pin_x`, `pin_y` ∈ [0, 1] — ułamek szerokości/wysokości obrazu ekranu,
  który odpowiada granicom frame'u (0,0 = lewy górny róg frame'u). Ułamki nie zależą od rozmiaru okna, zoomu,
  DPR ani orientacji, bo obraz skaluje się proporcjonalnie.
- **`surface_confirmed`:** ekran przyjmuje pinezki tylko, gdy wymiary obrazu = granice frame'u × skala podglądu (±1 px).
  Inaczej (np. treść wystaje poza frame) — uwagi tylko do ekranu, bez pinezki (UI i serwer: 422).
- **Fallback bez ekranu:** uwaga do prototypu ma wymagany opis kontekstu (`context`, ≤190 znaków) i nie ma
  współrzędnych. Znacznik z warstwy prototypu (etap 06) nigdy nie jest wysyłany. Pinezka bez ekranu → 422.
- Ekran dodaje opiekun (`manageReviews`) linkiem do frame'u z tego samego pliku co prototyp rewizji
  (inny `file_key` → błąd); limit 30 ekranów na rewizję.
- Uwaga = wątek (`fmi_review_threads`: numer ciągły w projekcie, rewizja, ekran, kontekst, pinezka, `pin_reference`,
  `figma_version`) + pierwszy komentarz (`fmi_review_comments`: treść ≤2000, autor pracownik/klient, data).
  Numer nadawany w transakcji z blokadą wiersza projektu.

## Zmiany

- Migracja `2026_11_03_000300_add_review_screens_and_comments` (wykonana, batch 9; próba na kopii 87/87,
  odtworzenie kopii 87/87, kopia `~/Desktop/fillmein-pre-restore-check-20261103-210809.sql`).
- Modele `ReviewScreen`, `ReviewComment`; rozszerzony `ReviewThread`; morph map `review_screen`, `review_comment`.
- `ReviewScreenService`, `ReviewCommentService`, `StoreReviewScreenRequest`, `StoreReviewCommentRequest`;
  `FigmaFrameService::fetch(Page)` → `fetchTarget(Project, target, …, ?Page)` (zachowanie dla podstron bez zmian).
- Trasy: `POST design-review/{project}/screens`, `GET …/screens/{screen}/image` (w zakresie projektu),
  `POST …/comments` (throttle `fmi-review-comments`, 20/min).
- Widoki: wybór ekranu w trybie Komentuj, obraz ekranu z pinezkami, karta „Ekrany rewizji”, lista „Uwagi”
  (`#uwaga-N`, autor, data, rewizja, ekran/kontekst, „bez pinezki”); treść escapowana `{{ }}`.
- `review-composer.js`: pinezka liczona od `getBoundingClientRect()` obrazu, zmiana ekranu usuwa pinezkę, zapis JSON z CSRF.

## Testy

- `tests/review-comments/run.sh` — 34/34 (serwis 18/18, przeglądarka 33/33): pozycja pinezki po zapisie
  i przeładowaniu przy 1280/768/375/1600 px, zoom 1.5 i 0.67, pion/poziom telefonu z DPR 3, przewinięcie
  ekranu (zapis względem obrazu, nie okna), kliknięcie w margines, zmiana ekranu, ekran niepotwierdzony,
  brak ekranu; walidacja x/y, XSS, CSRF 419, obcy projekt/ekran 404/422, limit 429.
- `tests/review-modes` dostosowany (pole kontekstu, przeładowanie po zapisie).

## Ograniczenia

- Pinezki na prototypie interaktywnym — niemożliwe bez zdarzeń Embed API (rejestracja originu w aplikacji OAuth Figmy — decyzja właściciela).
- Ekran to statyczny obraz frame'u z chwili dodania; zmiana w Figmie wymaga ponownego dodania (zapisany `figma_version`).
- Odpowiedzi w wątku, statusy i uwagi klientów — kolejne etapy.

## Wycofanie

`bash tests/bin/wp.sh acorn migrate:rollback --step=1` (usuwa ekrany, komentarze i nowe kolumny wątków) + przywrócenie
plików z etapu 06; ewentualnie odtworzenie kopii bazy wskazanej wyżej.
