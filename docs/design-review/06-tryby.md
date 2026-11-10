# Design Review — etap 06: tryby Przeglądaj / Komentuj

Data: 2026-11-03. Zależności: 05 (osadzenie; zdarzenia Embed API niepotwierdzone, geometria niepotwierdzona).

## Fakty i decyzje
- Przełącznik nad prototypem: grupa dwóch przycisków (`aria-pressed`), opis bieżącego trybu (`aria-live`).
- **Przeglądaj** (domyślny): brak warstwy, ramka Figmy dostaje kliknięcia, dotyk i klawiaturę.
- **Komentuj**: ramka Figmy dostaje atrybut `inert` (bez fokusu i zdarzeń) i `tabindex=-1`, nad nią warstwa (przycisk
  `absolute inset-0`) przyjmuje kliknięcie / tap. Napis „Tryb Komentuj — prototyp zatrzymany”. Nie da się jednocześnie
  nawigować prototypu i dodać uwagi — warstwa i ramka nigdy nie są aktywne naraz.
- Wskazanie miejsca: znacznik w pozycji 0–1 względem warstwy, **tylko na czas pisania**. Nie jest wysyłany ani zapisywany
  (geometria ekranu Figmy niepotwierdzona — etap 05). Formularz mówi wprost: „orientacyjnie, nie zapisywane jako pinezka”.
- Tryb awaryjny ekranu: pole „Ekran” (wymagane, maks. 190 znaków) wypełnia użytkownik; wypełniane automatycznie tylko
  z potwierdzonego zdarzenia `PRESENTED_NODE_CHANGED` (komponent `figmaEmbed`). Przycisk „Uwaga do ekranu” i Enter
  na warstwie — uwaga bez wskazania.
- Utrata pewności: zmiana rozmiaru warstwy (ResizeObserver) lub zmiana ekranu ze zdarzenia → znacznik usunięty,
  komunikat, szkic zostaje.
- Anulowanie: „Anuluj” / Escape zamyka formularz (szkic zostaje), drugi Escape wraca do Przeglądaj; „Odrzuć szkic”
  czyści. Fokus: formularz → treść uwagi; zamknięcie → przycisk „Komentuj”. Widoczny fokus (`focus-visible:ring`).
- Szkic (treść + ekran) w `sessionStorage` tej karty (`fmi-review-draft:{projekt}:{rewizja}`), przetrwa przełączenie trybu
  i przeładowanie; znika po zapisie lub odrzuceniu. Bez danych logowania ani tokenów.
- **Zapis uwag na serwerze to etap 07** (model komentarzy, autor, rewizja, uprawnienia). Teraz formularz nie ma adresu
  zapisu i mówi o tym wprost, zachowując szkic. Obsługa błędów zapisu (500, 403/404, brak sieci, sukces) jest w komponencie
  i sprawdzona na podstawionym adresie; wysyłka zawiera tylko `body` i `screen` oraz nagłówek CSRF.
- Bez zmian w bazie i w Treściach.

## Zmiany
| Plik | Zmiana |
|---|---|
| `resources/js/fillmein/review-composer.js`, `resources/js/fillmein.js` | komponent `reviewComposer` |
| `resources/views/fillmein/design-review/partials/composer.blade.php` | przełącznik, formularz, szkic |
| `resources/views/fillmein/design-review/partials/embed.blade.php` | warstwa Komentuj, znacznik, `inert` (opcja `$commentable`) |
| `resources/views/fillmein/design-review/show.blade.php` | osadzenie przez composer |
| `tests/review-modes/` (`modes.mjs`, `run.sh`), `tests/all/run.sh` | testy |

## Testy
`bash tests/review-modes/run.sh` → **46 PASS, 0 FAIL** (headless Chromium, publiczny prototyp „Embed Kit 2.0 examples”;
dwa kolejne przebiegi zielone). Zrzuty: `/tmp/fmi-review-modes-shots/` (01 formularz w Komentuj, 02 telefon).
- Ten sam punkt (produkt na ekranie 5-3): Przeglądaj → nawigacja do 3-1075, bez formularza; Komentuj → ekran zostaje 5-3,
  znacznik + formularz, fokus w treści. Ramka `inert` w Komentuj, bez `inert` w Przeglądaj.
- Escape: zamyka formularz (fokus na „Komentuj”, szkic zostaje), drugi Escape → Przeglądaj; Anuluj i „Odrzuć szkic”.
- Przełączenie podczas pisania: formularz ukryty, prototyp znów nawiguje, szkic zachowany; po przeładowaniu przywrócony;
  „Wróć do szkicu”.
- Klawiatura: Tab Przeglądaj → Komentuj, Enter włącza tryb; warstwa z widocznym fokusem, Enter → uwaga do ekranu.
- Walidacja: brak opisu ekranu; brak endpointu (etap 07) → jawny komunikat. Błędy zapisu na podstawionym adresie: 500, 403,
  brak sieci — komunikat i szkic zostają; 201 → szkic wyczyszczony (także z sessionStorage); wysyłka z CSRF, tylko `body`, `screen`.
- Utrata pewności: resize 1280 → 900 px usuwa znacznik z komunikatem; zdarzenie zmiany ekranu (zasymulowane jako zdarzenie
  komponentu `figmaEmbed` — prawdziwe zdarzenia Figmy nie docierają, etap 05) usuwa znacznik i uzupełnia ekran.
- Telefon 390×844 (dotyk): tap w Komentuj → znacznik i formularz bez nawigacji; Anuluj tapem; Przeglądaj + tap → nawigacja;
  bez przewijania poziomego.
- axe (WCAG 2.2 AA) dla przełącznika i formularza: 0 naruszeń (najpierw wykrył `label-content-name-mismatch` warstwy — poprawione).
- Regresja: `review-embed` 14/14 + 37/37, `review-panel` 71/71, `lint` kod 0, `stage14` E2E 68/68, audyt UI 99/99, macierz 436/436.

Niewykonane: prawdziwy zapis uwagi na serwerze (etap 07), prawdziwe zdarzenia zmiany ekranu Figmy (niedostępne — etap 05),
fizyczny telefon i Safari.

## Ograniczenia
- Bez zdarzeń Figmy aplikacja nie zna ekranu — użytkownik musi go opisać; znacznik nie przenosi się z prototypem.
- Warstwa przykrywa też stopkę Figmy (pełny ekran, nazwa pliku) w trybie Komentuj.
- `inert` — Chrome 102+, Safari 15.5+, Firefox 112+; starsze przeglądarki: warstwa nadal przechwytuje mysz i dotyk, ale klawiatura
  mogłaby dotrzeć do ramki (nie testowano).

## Wycofanie
Bez migracji. Przywrócić pliki z tabeli „Zmiany” (`show.blade.php` z etapu 05 osadza `partials/embed` bez composera) i `yarn build`.
