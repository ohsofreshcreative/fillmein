# Design Review — etap 10: akceptacja rewizji

## Model
- `fmi_review_decisions` (migracja `2026_11_03_000600`, addytywna; batch 12): **jedna decyzja na rewizję**
  (unikalny `revision_id`), tylko dopisywana. Pola: decyzja (`approved` | `changes_requested`), komentarz,
  aktor (`actor_type`, `actor_user_id` / `actor_access_id`, `actor_name`), sposób identyfikacji, czas serwera
  (`created_at`), numer rewizji i utrwalony kontekst Figmy: `figma_url`, `figma_file_key`, `figma_node_id`,
  `figma_version`, `figma_last_modified`, `version_status` (`pinned` | `unverified`). `request_id` — idempotencja.
- Status rewizji (`fmi_review_revisions.status`) odzwierciedla decyzję; `draft` ma etykietę „Oczekuje na decyzję”.

## Kto decyduje
- Panel: osoba **przypisana do projektu** z `fmi_manage_reviews` (ProjectPolicy@decideReview) — konto WordPress,
  identyfikacja `wp_account`.
- Klient: link recenzji z prawem **akceptacji** (`can_approve`, middleware `fmi.review-guest:approve`) po podaniu nazwy.
  Identyfikacja `review_link` albo `review_link_code` (link z kodem). Nazwa jest **deklarowana, niepotwierdzona**.
  Formularz mówi wprost, że to nie jest podpis elektroniczny.
- Członek projektu bez `fmi_manage_reviews`, osoby nieprzypisane, linki bez akceptacji, odwołane i wygasłe — odmowa.

## Rewizje i historia
- Decyzja dotyczy rewizji, którą osoba widziała (`revision` w formularzu) i tylko **bieżącej** (najnowszej).
- Kolejna decyzja wymaga **jawnie utworzonej nowej rewizji** („Utwórz nową rewizję”, `fmi_manage_reviews`):
  nowa ma ten sam link prototypu i status „Oczekuje na decyzję”; poprzednia zachowuje decyzję (historia na karcie).
  Poprzednia rewizja bez decyzji staje się „Zastąpiona”.
- Równoległe decyzje: blokada wiersza rewizji + unikalny indeks — wygrywa pierwszy zapis, pozostałe dostają 409
  z treścią obowiązującej decyzji; ponowienie z tym samym `request_id` zwraca zapisaną decyzję.

## Figma: ten sam URL, inna zawartość
- Link Figmy zawsze pokazuje bieżący stan pliku. W chwili decyzji serwer odczytuje `version` i `lastModified`
  pliku przez istniejące połączenie OAuth (`GET /v1/files/:key/nodes` dla węzła rewizji — bez dowolnych adresów).
  Sukces → `pinned`; brak dostępu / błąd → `unverified` i jawny komunikat na karcie decyzji.
- „Sprawdź, czy plik się zmienił” porównuje bieżącą wersję z utrwaloną: `changed` → ostrzeżenie i wskazanie
  nowej rewizji; `unknown`, gdy nie ma wersji do porównania. Decyzja nigdy nie przechodzi na nową wersję sama.
- Ograniczenie dowodowe: `version` identyfikuje wersję pliku, a nie pojedynczego ekranu ani stanu prototypu.
  Decyzja `unverified` dotyczy zawartości linku z chwili decyzji bez dowodu, jaka to była wersja.

## Trasy
- `POST /app/design-review/{project}/decision` (throttle `fmi-review-status`)
- `POST /app/design-review/{project}/revisions`
- `POST /app/design-review/{project}/decisions/{decision}/check` (throttle `fmi-review-status`)
- `POST /review/decision` (throttle `fmi-review-guest`, `fmi.review-guest:approve`)

## Testy
- `tests/review-decisions/run.sh` — serwis (atrapa FigmaClient: zmiana `version` pod tym samym URL) i HTTP:
  A → B, brak uprawnień, link bez akceptacji i odwołany, 6 równoległych żądań, ograniczenie wersji w widoku.
- `tests/stage14` — nowe trasy w macierzy bezpieczeństwa.

## Wycofanie
1. Wyeksportuj decyzje (kopia bazy) — to dowód akceptacji.
2. `down()` migracji odmawia, gdy tabela ma wiersze; po ręcznym usunięciu wierszy: `wp acorn migrate:rollback --step=1`.
3. Przywróć pliki z etapu 09 (kontrolery, widoki, trasy, enum, serwisy) albo odtwórz kopię
   `~/Desktop/fillmein-pre-restore-check-20261103-223710.sql`.
