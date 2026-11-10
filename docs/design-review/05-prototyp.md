# Design Review — etap 05: interaktywny prototyp Figmy

Data: 2026-11-03. Zależności: 04 (panel, zapis linku prototypu).

## Źródła (oficjalna dokumentacja, odczyt 2026-11-03)
- developers.figma.com/docs/embeds/embed-figma-prototype — adres `https://embed.figma.com/proto/{key}`, parametry:
  `embed-host` (wymagany), `client-id` (tylko Embed API), `node-id`, `starting-point-node-id`, `version-id`, `footer`,
  `viewport-controls`, `disable-default-keyboard-nav`, `show-proto-sidebar`, `hotspot-hints`, `device-frame`,
  `scaling` (`scale-down` domyślnie, `contain`, `min-zoom`, `scale-down-width`, `fit-width`, `free`), `content-scaling` (`fixed`, `responsive`).
- developers.figma.com/docs/embeds/embed-api — zdarzenia `INITIAL_LOAD`, `PRESENTED_NODE_CHANGED` (`presentedNodeId`,
  `isStoredInHistory`, `stateMappings`), `MOUSE_PRESS_OR_RELEASE` (m.in. `targetNodeId`, `targetNodeMousePosition`,
  `nearestScrollingFrameOffset`), `NEW_STATE`, `REQUEST_CLOSE`, `LOGIN_SCREEN_SHOWN`, `PASSWORD_SCREEN_SHOWN`; sterowanie
  `NAVIGATE_FORWARD`, `NAVIGATE_BACKWARD`, `RESTART`, `NAVIGATE_TO_FRAME_AND_CLOSE_OVERLAYS`, `CHANGE_COMPONENT_STATE`.
  Wymaga `client-id` aplikacji OAuth i originu strony dodanego w tej aplikacji („Add an embed origin”); komunikaty
  z/do `https://www.figma.com`.
- developers.figma.com/docs/embeds/embed-kit-2.0 — Embed Kit 1.0 (`figma.com/embed`) nie obsługuje już Embed API.
- developers.figma.com/docs/embeds/security-access — osadzenie respektuje udostępnienie pliku (hasło → prośba o hasło,
  plik organizacji → logowanie).
- developers.figma.com/docs/embeds/troubleshooting — osadzenie wymaga zgody przeglądarki na treści osadzone i Storage Access API.

## Macierz możliwości
| Możliwość | Dokumentacja | Wynik w naszym środowisku (headless Chromium, fillmein.local) | Status |
|---|---|---|---|
| Osadzenie prototypu (`embed.figma.com/proto`) | tak | renderuje się; ramka przekierowuje do `www.figma.com/proto/…` | **potwierdzone** |
| Przyciski / hotspoty / przejścia | tak (prototyp) | kliknięcie produktu: ekran 5-3 → 3-1075, powrót → 5-3, ArrowRight → 3-1075 | **potwierdzone** |
| Przewijanie wewnątrz ekranu | — | kółko nad telefonem przewija ekran (ten sam węzeł, inny obraz) | **potwierdzone** |
| Przewijanie strony poza ramką | — | działa (375 px) | **potwierdzone** |
| Dotyk | brak opisu | `isMobile` + `hasTouch` 390×844: tap → przejście 5-3 → 3-1075 | **potwierdzone (emulacja)** |
| Skalowanie / resize | `scaling`, `content-scaling` | 1280 → 768 → 375 → 1280: canvas w granicach ramki, przeskalowany, bez przewijania poziomego | **potwierdzone** (domyślne `scale-down`) |
| Brak dostępu | tak (sharing settings) | plik niedostępny: Figma pokazuje „Whoops! … file may not be publicly accessible”, bez canvas | **potwierdzone** |
| Zdarzenia (`INITIAL_LOAD`, `PRESENTED_NODE_CHANGED`, `LOGIN_SCREEN_SHOWN`…) | tak, z `client-id` + zarejestrowany origin | `client-id` wysłany, **0 komunikatów** od `https://www.figma.com` (publiczny, prawdziwy i niedostępny plik) | **niepotwierdzone** — origin `fillmein.local` nie jest dodany w aplikacji OAuth |
| Identyfikacja ekranu (`presentedNodeId`) | tak (zdarzenie) | niedostępna bez zdarzeń | **niepotwierdzone** |
| Granice widocznego obszaru / geometria | częściowo (`targetNodeMousePosition`, `nearestScrollingFrameOffset` w `MOUSE_PRESS_OR_RELEASE`) | brak zdarzeń; brak udokumentowanych granic viewportu i skali | **brak** — nie używamy |
| Sterowanie z aplikacji (`NAVIGATE_*`, `RESTART`) | tak | ukryte, dopóki nie przyjdzie zdarzenie Figmy | **niepotwierdzone** |
| Odczyt DOM ramki | nie (same-origin) | aplikacja go nie odczytuje; tylko test w Playwright | nie dotyczy |

## Decyzje
- Osadzenie oficjalne: `FigmaEmbed::forLink()` buduje `https://embed.figma.com/{proto|design}/{key}` wyłącznie ze zwalidowanego
  linku (`FigmaUrl::prototype`), z parametrami `embed-host=fillmein`, `node-id`, `starting-point-node-id`; inne parametry
  z linku nie przechodzą. `client-id` dołączany, gdy aplikacja OAuth ma poprawny identyfikator (`config fillmein.figma.embed.embed_api`).
- Link `/design/…` → `embed.figma.com/design` (podgląd pliku, bez przejść) z jawną informacją.
- Ramka: `allow="fullscreen"`, `referrerpolicy="no-referrer"`, bez `sandbox` (Figma potrzebuje skryptów, pamięci i logowania;
  sandbox bez prób na każdym typie pliku byłby niesprawdzony). Strona recenzji wysyła `Content-Security-Policy: frame-src
  https://embed.figma.com https://www.figma.com` (ramka przekierowuje do www.figma.com).
- Komunikaty: przyjmowane tylko gdy `origin === https://www.figma.com`, `source === iframe.contentWindow`, obiekt z `type`
  z listy udokumentowanych; `presentedNodeId` sprawdzany wzorcem. Podrobiony komunikat jest ignorowany (test).
- **Wariant awaryjny (obecny stan):** bez zdarzeń aplikacja nie pokazuje bieżącego ekranu ani sterowania; widoczny komunikat
  „aplikacja nie zna bieżącego ekranu”, wskazówka o udostępnieniu pliku i „Otwórz w Figmie”. Brak odpowiedzi ramki przez 20 s
  → komunikat o niedostępnym osadzeniu. Dla etapów 07–08: komentarz do jawnie wybranego ekranu / kontekstu bez pinezki,
  dopóki zdarzenia nie zostaną potwierdzone.
- Bez zmian w bazie (brak migracji) i bez zmian w Treściach.

## Zmiany
| Plik | Zmiana |
|---|---|
| `app/Integrations/Figma/FigmaEmbed.php` | nowy: adres osadzenia |
| `config/fillmein.php` | `figma.embed` (adres, host, origin zdarzeń, frame-src, embed_api) |
| `app/Http/Controllers/App/DesignReviewController.php` | `show`: dane osadzenia, CSP `frame-src` |
| `resources/js/fillmein/figma-embed.js`, `resources/js/fillmein.js` | komponent Alpine `figmaEmbed` |
| `resources/views/fillmein/design-review/partials/embed.blade.php`, `show.blade.php` | ramka, stany, komunikaty |
| `tests/review-embed/` (`unit.php`, `fixtures.php`, `embed.mjs`, `run.sh`), `tests/all/run.sh` | testy |

## Testy
`bash tests/review-embed/run.sh` → **PASS** (jednostkowe 14/14, przeglądarka 37/37). Zrzuty: `/tmp/fmi-review-embed-shots/`
(01 start, 02 po kliknięciu, 03 resize 375, 04 dotyk, 05 zablokowana sieć, 06 brak dostępu).
- Prototyp publiczny (Figma „Embed Kit 2.0 examples”): strona 200, CSP, `src`, atrybuty ramki, render, hotspot, powrót,
  klawiatura, przewijanie wewnątrz, brak błędów JS i naruszeń CSP.
- Zdarzenia: 0 komunikatów od Figmy we wszystkich próbach → UI bez „aktualnego ekranu” i sterowania (sprawdzone).
- Podrobione `postMessage` z originu strony → zignorowane.
- Resize 768/375/1280, przewijanie strony, dotyk 390×844.
- Prawdziwy plik projektu (`FMI_FIGMA_LIVE_URL` jako prototyp): renderuje się — plik jest udostępniony linkiem publicznie, więc nie
  sprawdza braku dostępu. Brak dostępu sprawdzony kluczem bez dostępnego pliku (ekran Figmy „file may not be publicly accessible”).
- Zablokowana sieć do figma.com → stan „Figma nie odpowiada”, reszta strony działa.
- Niepoprawny zapisany link → komunikat bez ramki; brak linku → pusty stan.

- Regresja zestawów dotkniętych zmianą: `lint` kod 0, `review-panel` 71/71, `stage14` E2E 68/68, audyt UI 99/99, macierz 97 tras 436/436.
  Pełnego `tests/all` w tym etapie nie uruchamiano (zmiany ograniczone do strony recenzji; ostatni pełny przebieg — etap 04).

Niewykonane:
- Prototyp chroniony hasłem i plik organizacji z logowaniem — brak takiego pliku testowego; obsługa opiera się na ekranach
  Figmy i (po rejestracji originu) zdarzeniach `PASSWORD_SCREEN_SHOWN` / `LOGIN_SCREEN_SHOWN`, nieprzetestowanych.
- Zdarzenia Embed API — wymagają dodania originu w aplikacji OAuth w Figmie (decyzja/akcja właściciela; dokumentacja nie mówi,
  czy przyjmie `http://fillmein.local`).
- Prawdziwe urządzenie dotykowe i Safari (Storage Access API) — tylko emulacja Chromium.

## Ryzyka i ograniczenia
- Bez zdarzeń nie da się wiarygodnie wiązać komentarzy z ekranem — etapy 07–08 muszą użyć wariantu „ekran wskazany ręcznie”.
- `embed-host=fillmein` jest identyfikatorem hosta; Figma nie opisuje jego walidacji.
- `figma_version` nie jest utrwalana — osadzenie pokazuje bieżącą wersję pliku (`version-id` możliwe w przyszłości).
- Wykrycie niedostępności ramki bez zdarzeń jest heurystyczne (timeout zdarzenia `load`).

## Wycofanie
Bez migracji. Przywrócić pliki z tabeli „Zmiany” (w `show.blade.php` wraca link zamiast ramki) i `yarn build`.
