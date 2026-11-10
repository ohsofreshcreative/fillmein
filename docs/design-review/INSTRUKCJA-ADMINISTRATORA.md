# Design Review — instrukcja dla zespołu (administrator, opiekun projektu, członek)

Design Review działa we wspólnym systemie projektów Fill Me In. Ten sam projekt może mieć moduł Treści,
moduł Design Review albo oba naraz. Konta, role i przypisania są te same co w Treściach.

## Kto co może
| Czynność | Kto |
|---|---|
| Włączenie modułu Design Review w projekcie | osoba, która może edytować projekt (ustawienia projektu) |
| Oglądanie recenzji, dodawanie uwag i odpowiedzi | każda osoba przypisana do projektu (autor, opiekun, członek) |
| Podstrony, link prototypu, zmiana statusu uwag, nowa rewizja, decyzja o rewizji | przypisana osoba z uprawnieniem `fmi_manage_reviews` (role Project Manager, Administrator) |
| Linki dla klienta (tworzenie, unieważnianie) | przypisana osoba z `fmi_manage_client_access` |
| Edycja własnej wiadomości | tylko jej autor |
| Osoba nieprzypisana | nie widzi projektu (404), także z linku w powiadomieniu |

## Krok po kroku
1. **Projekt → Design Review.** Menu „Projekty” → projekt → zakładka Design Review.
2. **Podstrony.** Karta „Podstrony”: w każdym wierszu nazwa (np. „Strona główna”) i link do frame'u z Figmy
   (w Figmie: zaznacz frame → „Copy link to selection”). „+ Dodaj podstronę” dodaje wiersz, „Zapisz podstrony” pobiera
   wszystkie naraz. Puste pole nazwy = nazwa frame'u z Figmy. Pierwsza rewizja tworzy się sama. Błędny link nie blokuje
   pozostałych — wraca do formularza z komunikatem. Podstrony muszą pochodzić z jednego pliku Figmy.
   **Link do prototypu** (zwinięty pod listą) jest opcjonalny — służy tylko do przycisku „Otwórz prototyp w Figmie”.
3. **Dymki.** Każda podstrona to pełny obraz z Figmy, przycięty do granic frame'u.
   Między podstronami przechodzisz zakładkami nad obrazem, strzałkami ‹ › albo klawiszami ← →. Kliknięcie w miejsce na
   ekranie otwiera dymek nowej uwagi, a pinezka zapisuje położenie względem obrazu. Kliknięcie istniejącego dymka
   otwiera wątek: odpowiedź, „Rozwiąż”, „Pokaż na liście”. Rozwiązane dymki są ukryte (przełącznik „Pokaż rozwiązane”).
   „Uwaga do całego ekranu” dodaje uwagę bez pinezki. Bez ekranów uwaga dostaje opis miejsca.
4. **Uwagi.** Statusy: Nowy → W trakcie → Rozwiązany (możesz otworzyć uwagę ponownie). Filtry pokazują liczniki.
   Historia uwagi pokazuje każdą zmianę statusu i edycję. Gdy dwie osoby zmieniają to samo naraz, druga dostaje komunikat
   o konflikcie i odświeża stan.
5. **Link dla klienta.** Karta „Linki dla klienta”: uprawnienia (podgląd zawsze, komentowanie,
   akceptacja), opcjonalnie kod dostępu. **Pełny adres widać tylko raz**, więc od razu go skopiuj i przekaż klientowi.
   Link nie daje dostępu do pliku w Figmie. Jeśli klient ma widzieć prototyp, udostępnij plik w Figmie osobno.
   Link możesz unieważnić w każdej chwili: kolejne żądania klienta są wtedy odrzucane.
6. **Decyzja o rewizji.** Karta „Decyzja — Rewizja N”: akceptacja albo prośba o poprawki (z opisem). Każda rewizja ma
   tylko jedną decyzję. Zapisujemy osobę, sposób identyfikacji (konto albo link z nazwą deklarowaną), czas serwera i wersję
   pliku Figmy, jeśli API ją podało („wersja nieustalona”, gdy nie podało).
7. **Zmiany w Figmie po decyzji.** Link Figmy zawsze pokazuje bieżący stan pliku. „Sprawdź, czy plik się zmienił”
   porównuje wersję pliku z wersją z chwili decyzji. Po zmianach kliknij **„Utwórz nową rewizję”**. Nowa rewizja czeka na
   decyzję, a poprzednia zostaje w historii.
8. **Powiadomienia.** Menu „Powiadomienia” (licznik nieprzeczytanych): nowe uwagi, odpowiedzi i decyzje w Twoich projektach.
   Kliknięcie otwiera właściwe miejsce i oznacza powiadomienie jako przeczytane. Powiadomień e-mail nie ma (odroczone).
9. **Historia projektu.** Menu „Treści” → projekt → dziennik aktywności (`/app/projects/{projekt}/activity`), filtr „Design Review”: kto, kiedy i co zrobił (bez tokenów linków).

## Treści — bez zmian
Moduł Treści, stare linki klientów `/p/…`, autozapis, zdjęcia i eksport działają jak dotąd. Linki recenzji (`/r/…`)
są oddzielne: link Treści nie otwiera recenzji, a link recenzji nie otwiera Treści.

## Dobre praktyki
- Klientowi dawaj link z najkrótszym potrzebnym terminem i tylko te uprawnienia, których potrzebuje. Gdy link mógł trafić
  do niepowołanych osób, dodaj kod dostępu.
- Nazwa klienta przy uwadze i decyzji jest **deklarowana**, nie potwierdzona. To nie jest podpis elektroniczny.
- Akceptacja dotyczy rewizji i wersji pliku z chwili decyzji. Gdy plik zmieni się później, wymagaj nowej rewizji.
- Usunięcie osoby z projektu od razu odbiera jej dostęp, także do wcześniejszych powiadomień.
