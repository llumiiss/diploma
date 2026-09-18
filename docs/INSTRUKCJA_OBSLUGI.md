# CertiSub Assistant — instrukcja obsługi i scenariusz demonstracji

> Stan na 2026-09-17, po Etapie 7 — wykonane są wszystkie etapy planu (§7 w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md)). Opisuje aplikację na danych z `scripts/seed-demo-data.php`.
> Dokument służy dwóm celom: pokazaniu wszystkich paneli i funkcji oraz jako zalążek rozdziału „dokumentacja użytkownika” w pracy (§9 w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md)).

---

## 1. Uruchomienie

| Krok | Co zrobić |
|---|---|
| 1 | Laragon → **Start All** (Apache + MySQL). Bez tego aplikacja nie działa. |
| 2 | `php scripts/migrate.php` — po każdej aktualizacji kodu. Drugi przebieg niczego nie zmienia. |
| 3 | `php scripts/seed-demo-data.php --force` — dane do pokazania (odtwarza je od zera). |
| 4 | Otwórz `http://localhost/assistent_subscription/` |

Aplikacja nie pobiera niczego z internetu: arkusz stylów, biblioteka Vue i krój pisma są w katalogu `assets/`. Po zmianie klas w szablonach trzeba przebudować arkusz (`npm install && npm run css`) — sam kod PHP działa bez Node.

Adresy:

| Adres | Co to |
|---|---|
| `/` | strona główna (landing) |
| `/login.php` | logowanie kodem e-mail (OTP) |
| `/register.php` | **rejestracja wyłączona** — przekierowuje do logowania z informacją, że konta zakłada administrator (decyzja D3) |
| `/dashboard.php` | **panel** — ewidencja certyfikatów, użytkowników certyfikatów i płatników |
| `/logout.php` | wylogowanie |

Katalogi wewnętrzne (`classes/`, `config/`, `scripts/`, `storage/`, `logs/`, `.git/`…) zwracają **403** — to celowe zabezpieczenie. Wywołanie API bez zalogowania (`/api/certificates.php`) zwraca **401**.

Interfejs startuje po polsku; język zmienisz przełącznikiem w nagłówku.

---

## 2. Konta, role i użytkownicy certyfikatów

### Konta personelu

Konta zakłada **administrator** w panelu: **Zarządzanie → Konta i role**. Nie ma haseł — osoba loguje się kodem wysłanym na adres e-mail podany przy zakładaniu konta.

Hierarchia ról: **ADMIN > MANAGER > OPERATOR**.

| Rola | Co może |
|---|---|
| ADMIN | wszystko, co MANAGER, oraz konta i role (zakładanie, zmiana roli, wyłączanie, przekazywanie rekordów), **szablony i załączniki**, **ustawienia** procesu odnowień, **dziennik zdarzeń** całego systemu, **import danych i wiadomości EML** |
| MANAGER | dane całej organizacji, archiwizacja i przywracanie, ekran **Archiwum** (także historia certyfikatów w kartach i rekordy z archiwum w wyszukiwarce), wybór opiekuna certyfikatu, **przydział zadań**, ponowne otwieranie zadań, **uruchamianie skanera**, statystyki wg osób, **eksport danych** |
| OPERATOR | ewidencja w swoim zakresie: certyfikaty, których jest opiekunem (albo ma do nich przydzielone zadanie), oraz osoby i płatnicy, których sam wprowadził lub którzy są z tymi certyfikatami powiązani; **zadania ToDo, zaproszenia i przypomnienia, odnawianie certyfikatów, raporty (karty osób i płatników, harmonogram) i wyszukiwarka** w tym zakresie; nie archiwizuje i nie widzi archiwum |

Kontrolę dostępu wykonuje serwer (API) — ukryte przyciski w przeglądarce to tylko wygoda. Szczegóły reguły zakresu danych: decyzja **D8** w mapie projektu.

Konta na danych demo:

| E-mail | Rola | Uwaga |
|---|---|---|
| `mobi.litosh@gmail.com` | ADMIN | Twoje konto do demonstracji — opiekun 2 aktywnych certyfikatów |
| dwa wcześniej założone konta testowe | OPERATOR | konta istniejące przed Etapem 0 |
| `ewa.pawlak@example.com` | MANAGER | konto demo personelu — opiekunka 4 certyfikatów |
| `tomasz.wrobel@example.com` | OPERATOR | konto demo personelu — opiekun 4 certyfikatów |
| `adam.nowicki@example.com` | OPERATOR, **wyłączone** | przykład konta osoby, która odeszła — nie może się zalogować |

Konta `@example.com` służą jako opiekunowie rekordów i osoby przypisane do zadań — nie da się na nie zalogować, bo nie ma dostępu do tych skrzynek.

Awaryjnie role można też zmienić z wiersza poleceń: `php scripts/set-role.php --list` / `php scripts/set-role.php <e-mail> <rola>`.

### Użytkownicy certyfikatów (beneficjenci)

To **osoby, których dotyczą certyfikaty** — nie są to konta logowania. Każda może być powiązana z płatnikiem.

| Osoba | Płatnik | Certyfikat |
|---|---|---|
| Jan Kowalski | NovaTech Sp. z o.o. | kwalifikowany — **wygasł** |
| Maria Wójcik | NovaTech Sp. z o.o. | pieczęć kwalifikowana NovaTech — odnowienie w toku |
| Piotr Lewandowski | Grupa Wisła S.A. | kwalifikowany — ważny |
| Michał Kamiński | Grupa Wisła S.A. | brak (osoba bez certyfikatu) |
| Agnieszka Mazur | Fundacja Cyfrowy Śląsk | kwalifikowany — ważny |

---

## 3. Logowanie (bez hasła, kodem OTP)

1. `/login.php` → podaj e-mail → **Wyślij kod**.
2. Aplikacja wysyła 6-cyfrowy kod. Konfiguracja to `driver = sandbox`, więc **kod trafia do skrzynki Mailtrap** (mailtrap.io → Email Testing → Inboxes), a **nie** na prawdziwego Gmaila.
3. Gdyby SMTP nie zadziałał, włączone `dev_log_codes` zapisuje kod do `logs/otp.log` i logowanie i tak przejdzie — to awaryjne wyjście na pokazie.
4. Wpisz kod → trafiasz do panelu. Kod jest ważny 10 minut, ma limit 5 prób i jednorazowe użycie.

Warte pokazania jako element bezpieczeństwa:

- przy nieistniejącym **albo wyłączonym** koncie komunikat brzmi „jeśli konto istnieje, wysłaliśmy kod” — system nie zdradza, które adresy są w bazie;
- gdy administrator wyłączy konto zalogowanej osoby, jej sesja kończy się przy następnym kliknięciu.

---

## 4. Panel firmowy

Układ: nagłówek (nazwa aplikacji, język, konto, wylogowanie), menu po lewej, nad widokiem **wyszukiwarka globalna**, widok w środku. Adres widoku jest w pasku adresu (np. `dashboard.php#/payers`, a karta płatnika `dashboard.php#/payers/22`), więc odświeżenie strony zostawia Cię w tym samym miejscu. Kliknięcie wiersza otwiera **panel szczegółów** z prawej strony.

### Wyszukiwarka globalna

Pole nad każdym widokiem; skrót **Ctrl+K** albo **/**. Po wpisaniu co najmniej 2 znaków podpowiada wyniki w trzech grupach: **certyfikaty**, **użytkownicy certyfikatów**, **płatnicy**. Strzałki wybierają wynik, **Enter** go otwiera (certyfikat w panelu szczegółów, osobę i płatnika na karcie raportowej), a **Enter bez wybranego wyniku** albo „Pokaż wszystkie wyniki” przechodzi do pełnej listy (do 50 wyników w grupie, powiązania jako odnośniki).

Wyszukiwarka pokazuje powiązania i powód dopasowania:

| Wpisz | Znajdziesz |
|---|---|
| `nova` | płatnika NovaTech (nazwa), jego osoby (przez płatnika i e-mail) oraz certyfikaty (nazwa lub przez płatnika) |
| `5A3F9C21` | certyfikat po numerze seryjnym, a przez niego osobę i płatnika |
| `634-285-19` | płatnika po NIP-ie (kreski i spacje nie mają znaczenia) i jego certyfikaty |
| `600 100 200` | osobę po numerze telefonu |
| `Wróbel` | certyfikaty, których opiekunem jest Tomasz Wróbel |

Pod każdym wynikiem jest wiersz „dopasowano: …” albo „przez powiązanie: …”. Dopasowania bezpośrednie są wyżej. OPERATOR widzi tylko rekordy ze swojego zakresu, a rekordy z archiwum (oznaczone „W archiwum”) pojawiają się od roli MANAGER.

### Pulpit

Na danych demo (jako ADMIN):

| Element | Co pokazuje | Na danych demo |
|---|---|---|
| Karta „Certyfikaty w ewidencji” | liczba bieżących certyfikatów + aktywne/wygasłe | **10**, z tego 7 aktywnych i 1 wygasły |
| Karta „Odnowienia ≤ 30 dni” | certyfikaty w progu ostrzeżenia | **5**, z tego 2 krytyczne (≤ 7 dni) |
| Karta „Płatności do obsługi” | wkrótce + zaległe | **5**, w tym 1 zaległa |
| Karta „Zobowiązanie roczne” | suma kosztów w przeliczeniu na rok (cykl miesięczny × 12, wieloletni ÷ lata ważności) | **32 978,50 PLN** |
| Kafelki statusów | oczekujące / odnowienie w toku / aktywne / wygasłe | 1 / 1 / 7 / 1 |
| „Priorytetowe odnowienia” | najpilniejsze certyfikaty, sortowane po dniach | na górze wygasły certyfikat kwalifikowany Jana Kowalskiego |
| „Płatności wymagające działania” | płatności wkrótce i zaległe + suma | 5 pozycji, **11 579,00 PLN** |

Kolory wierszy: czerwony = wygasłe lub krytyczne, żółty = ostrzeżenie.

Zanim dane dojadą, panel pokazuje szare szkielety w miejscu kafelków i wierszy tabeli — układ nie przeskakuje po załadowaniu. Wskaźniki pulpitu i statystyki zadań są liczone raz na 5 minut (osobno dla każdej roli i konta) i odświeżają się natychmiast po każdej zmianie danych.

Pod kartami jest panel **„Statystyki realizacji zadań”**: słupki statusów (do zrobienia / w toku / zrobione / porzucone) z udziałem procentowym, otwarte zadania wg priorytetu, liczba zadań po terminie, moje otwarte, skuteczność (zrobione ÷ zamknięte) i średni czas realizacji. MANAGER i ADMIN widzą też rozkład zadań wg osób.

### Lista ToDo (zadania odnowień)

Zadania odnowień zakłada **skaner**: każdy bieżący certyfikat, który wszedł w margines odnowienia, dostaje zadanie przypisane opiekunowi. Margines to „wymagany czas odnowienia” certyfikatu, a gdy go nie ma — domyślny próg z **Ustawień** (30 dni). Skaner uruchamia codziennie cron (§7), a MANAGER i ADMIN mogą go uruchomić przyciskiem **Uruchom skaner** — na danych demo założy 1 zadanie (Microsoft 365 Business).

| Element | Działanie |
|---|---|
| Liczniki | otwarte, po terminie, moje otwarte, bez przydziału, zamknięte w ostatnich 30 dniach |
| Priorytet | **Wygasłe** (po dacie), **Krytyczne** (≤ próg krytyczny, 7 dni), **Do odnowienia** (w marginesie); skaner podnosi priorytet, gdy data się zbliża |
| Filtry | status (domyślnie otwarte), osoba (moje, bez przydziału, konkretna osoba), priorytet, wyszukiwanie |
| Statusy | **Rozpocznij** (do zrobienia → w toku; certyfikat dostaje status „odnowienie w toku”), **Zakończ** (z notatką), **Porzuć** (wymaga powodu), **Cofnij do „do zrobienia”**; zamknięte zadanie otwiera ponownie tylko MANAGER |
| Przydział | MANAGER i ADMIN wybierają osobę w panelu zadania (tylko aktywne konta); OPERATOR widzi certyfikaty, do których dostał zadanie |
| Odnów certyfikat | nowa data wygaśnięcia (musi być późniejsza), numer seryjny, płatność → powstaje nowy certyfikat z tymi samymi powiązaniami, stary trafia do archiwum, zadanie jest zrobione, a w historii obu certyfikatów widać łańcuch odnowień |

Panel zadania pokazuje też zaproszenia wysłane w ramach zadania i historię zdarzeń (otwarcie, zmiany statusu i priorytetu, przydział, wysyłki).

### Zaproszenia

**Wyślij zaproszenie** jest w liście ToDo, w panelu zadania i w szczegółach certyfikatu (sekcja „Odnowienie”). Okno wysyłki:

1. **Odbiorca** — użytkownik certyfikatu albo płatnik; odbiorca bez adresu e-mail jest wyszarzony.
2. **Szablon** — zaproszenie PL lub EN (lista aktywnych szablonów).
3. **Załączniki** — domyślnie te przypisane do szablonu; można dodać inne z biblioteki.
4. **Podgląd** — temat i treść wypełnione danymi certyfikatu (imię, typ, numer seryjny, data ważności, liczba dni, płatnik).

Wysyłka dopisuje wpis do rejestru, a zadanie certyfikatu przechodzi do statusu „w toku” (gdy certyfikat nie ma zadania, powstaje ono automatycznie). Nieudana wysyłka (np. błąd SMTP) zostaje w rejestrze z treścią błędu.

Ekran **Zaproszenia** to rejestr: data, certyfikat, odbiorca, status, liczba przypomnień i termin następnego. Akcje: **Przypomnij** (od razu, szablonem przypomnienia w języku zaproszenia), **Ponów** (nieudana wysyłka), **Odpowiedziano** (wstrzymuje przypomnienia), **Zamknij**. Filtr „tylko z zaległym przypomnieniem” pokazuje wysyłki czekające na cron. Na danych demo są 4 zaproszenia: wysłane z 2 przypomnieniami, z odpowiedzią, nieudane (błąd SMTP 421) i zamknięte.

Automatyczne przypomnienia wysyła cron (§7) co **7 dni** do limitu **3** — obie wartości zmienia ADMIN w Ustawieniach. Zakończenie lub porzucenie zadania zamyka jego zaproszenia i kończy przypomnienia.

### Szablony i załączniki (ADMIN)

- **Szablony** — nazwa, kod (`renewal_invitation` = zaproszenie, `renewal_reminder` = przypomnienie), język, temat, treść HTML i tekstowa, aktywność, przypisane załączniki. Przyciski z polami (`{imie}`, `{numer_seryjny}`, `{data_waznosci}`…) dopisują pole do treści, a **Podgląd** wypełnia szablon danymi przykładowego certyfikatu. Kod i język muszą być unikalne.
- **Załączniki** — biblioteka plików (PDF, DOC/DOCX, ODT, XLSX, TXT, CSV, PNG, JPG, ZIP do 10 MB). Aplikacja sprawdza rozszerzenie i faktyczną zawartość pliku, zapisuje go pod losową nazwą w `storage/attachments` (niedostępne z przeglądarki) i udostępnia do pobrania tylko zalogowanym. Plik używany w szablonie lub wysłany w zaproszeniu nie może zostać usunięty.

### Ustawienia (ADMIN)

| Ustawienie | Domyślnie | Wpływ |
|---|---|---|
| Domyślny margines odnowienia | 30 dni | kiedy skaner zakłada zadanie dla certyfikatu bez własnego wymaganego czasu odnowienia; próg „do odnowienia” na pulpicie |
| Próg krytyczny | 7 dni | priorytet „krytyczne” |
| Odstęp między przypomnieniami | 7 dni | kiedy cron wysyła kolejne przypomnienie |
| Limit przypomnień na zaproszenie | 3 | 0 wyłącza automatyczne przypomnienia |

Każda zmiana ustawień trafia do historii zdarzeń.

### Certyfikaty

- **Lista** z wyszukiwarką (nazwa, numer seryjny, wystawca, osoba, płatnik, opiekun) i filtrami: typ, status, płatność, priorytet.
- **Szczegóły** (kliknij wiersz): typ, status, numer seryjny, wystawca, ważność z liczbą dni, wymagany czas odnowienia, opiekun, koszt, płatność, notatki; odnośniki do osoby i płatnika; łańcuch odnowień (poprzedni certyfikat / zastąpiony przez); sekcja **Odnowienie** (otwarte zadanie, **Utwórz zadanie**, **Wyślij zaproszenie**, lista zaproszeń); **historia zdarzeń** — kto i kiedy dodał, zmienił (z listą zmienionych pól „z → na”), zarchiwizował, wysłał zaproszenie.
- **Dodaj / Edytuj** — formularz w sekcjach: dane certyfikatu, ważność i odnowienie, powiązania, koszt i płatność. Walidacja pokazuje błędy przy polach, np.:
  - data „ważny od” późniejsza niż wygaśnięcie,
  - numer seryjny zajęty u tego samego wystawcy,
  - nieistniejąca data (np. 31 lutego — serwer sprawdza datę kalendarzem).
- Po wybraniu osoby **płatnik podpowiada się sam**. Przyciski „+” przy polach osoby i płatnika dodają nowy rekord bez zamykania formularza.
- **Archiwizuj** (MANAGER, ADMIN) — certyfikat znika z bieżących list, historia zostaje, a otwarte zadanie odnowienia zamyka się jako porzucone.

### Użytkownicy certyfikatów

Lista osób z płatnikiem, liczbą certyfikatów i najbliższym wygaśnięciem. Szczegóły osoby pokazują jej certyfikaty; z panelu można dodać certyfikat od razu przypisany do tej osoby. Przycisk **Karta** w wierszu (i **Karta raportowa** w panelu szczegółów) otwiera kartę użytkownika certyfikatu — opis niżej w „Raportach”. Osoby z aktywnymi certyfikatami **nie da się zarchiwizować** — najpierw trzeba zarchiwizować certyfikaty albo przypisać je komuś innemu.

### Płatnicy

Lista z NIP-em, osobą kontaktową, miejscowością, liczbą osób i certyfikatów oraz kosztem rocznym. Szczegóły płatnika pokazują powiązane osoby i certyfikaty z sumą kosztów, a przycisk **Karta** otwiera kartę płatnika.

- **NIP** jest sprawdzany sumą kontrolną (można wpisać go z kreskami lub z prefiksem PL) i musi być unikalny. Przy próbie dodania istniejącego NIP-u formularz pokazuje nazwę istniejącego płatnika i odnośnik do niego — operator, który tego płatnika nie widzi, dostaje komunikat bez szczegółów.
- Płatnika z aktywnymi certyfikatami lub osobami **nie da się zarchiwizować** (komunikat podaje liczby).

### Raporty — perspektywy Użytkownika, Płatnika i Administratora

Widok **Raporty** odpowiada perspektywom z opisu systemu. Trzy kafelki: wybór osoby → **Otwórz kartę**, wybór płatnika → **Otwórz kartę**, a dla administratora skróty do dziennika zdarzeń, kont, szablonów i ustawień. Niżej jest **harmonogram wygaśnięć**.

**Karta użytkownika certyfikatu** (perspektywa Użytkownika; np. Jan Kowalski):

| Sekcja | Co pokazuje |
|---|---|
| Dane i wskaźniki | kontakt, płatnik (odnośnik do karty płatnika); bieżące certyfikaty, najbliższe wygaśnięcie, wymagające uwagi (wygasłe, krytyczne, w progu ostrzeżenia), otwarte zadania, wysłane zaproszenia z liczbą przypomnień, ostatni kontakt |
| Certyfikaty i daty odnowienia | ważność, **odnowienie od** (data wygaśnięcia minus wymagany czas odnowienia, a bez niego domyślny margines 30 dni) z oznaczeniem „w oknie odnowienia”, priorytet z liczbą dni, otwarte zadanie z osobą, kontakt; informacja, który certyfikat został odnowiony |
| Historia certyfikatów (archiwum) | certyfikaty zastąpione przy odnowieniu lub zarchiwizowane i to, co je zastąpiło — od roli MANAGER |
| Ścieżka realizacji | wszystkie zadania odnowień, także zamknięte, z notatką z realizacji |
| Zaproszenia i przypomnienia | wysyłki z odbiorcą, statusem, datą i terminem następnego przypomnienia |
| Historia na osi czasu | zdarzenia dotyczące osoby, pogrupowane wg dni, z filtrem: ewidencja / zadania / zaproszenia; nazwy certyfikatów otwierają szczegóły |

**Karta płatnika** (perspektywa Płatnika; np. NovaTech): te same sekcje oraz **powiązane osoby** (przypisane do płatnika i te, których certyfikaty płatnik opłaca), **harmonogram wygaśnięć na 12 miesięcy** z wykresem i **koszt roczny** (koszt miesięczny × 12, wieloletni podzielony przez lata ważności). Na danych demo: 2 osoby, 4 bieżące certyfikaty, 1 w archiwum, koszt 1 819,00 PLN.

**Harmonogram wygaśnięć** (w „Raportach”): bieżące certyfikaty wg miesiąca wygaśnięcia na 3, 6, 12 albo 24 miesiące, z filtrem płatnika i typu; już wygasłe są w grupie **Zaległe**. Każda pozycja ma odnośniki do certyfikatu, osoby, płatnika i zadania.

Karty i harmonogram mają przycisk **Drukuj** — wydruk (albo zapis do PDF w oknie drukowania) nie zawiera menu ani przycisków. OPERATOR widzi w kartach tylko swoje certyfikaty i zdarzenia, które ich dotyczą.

### Archiwum (MANAGER, ADMIN)

Trzy zakładki: certyfikaty, użytkownicy certyfikatów, płatnicy — z datą archiwizacji i przyciskiem **Przywróć**. Na danych demo są tu 2 certyfikaty (poprzedni SSL EV i porzucona domena). Certyfikatu nie da się przywrócić, jeśli jego płatnik lub osoba są w archiwum. Rekord z archiwum można obejrzeć, ale nie edytować.

### Import i eksport (MANAGER: eksport, ADMIN: import)

**Eksport.** Przyciski **Eksport CSV / XML** są przy listach (certyfikaty, użytkownicy certyfikatów, płatnicy, archiwum, lista ToDo, zaproszenia, dziennik zdarzeń), na kartach raportowych i przy harmonogramie, a wszystkie zbiory razem — w widoku **Import i eksport**.

- **CSV** jest dla arkusza: UTF-8 ze znacznikiem BOM, średnik, nagłówki i wartości słownikowe w języku interfejsu — Excel otwiera plik bez kreatora importu. Tekst zaczynający się od `=`, `+`, `-` lub `@` dostaje apostrof, żeby arkusz nie wykonał go jako formuły.
- **XML** jest dla innych systemów: nazwy pól i kody wartości; karty i harmonogram mają strukturę zagnieżdżoną (certyfikaty, zadania, zaproszenia, historia).
- Eksport obejmuje **zakres danych konta** (operator nie pobierze cudzych rekordów) i zapisuje zdarzenie w dzienniku — widać, kto i kiedy wyniósł dane osobowe.
- Dziennik zdarzeń eksportuje się z bieżącymi filtrami ekranu.

**Import (ADMIN).** Kolejność: **płatnicy → użytkownicy certyfikatów → certyfikaty**, bo kolejne pliki wskazują wcześniejsze rekordy (NIP, e-mail). Krok po kroku:

1. Wybierz rodzaj danych i tryb dla rekordów, które już są w ewidencji: **Pomiń** albo **Aktualizuj** (aktualizacja zmienia tylko kolumny obecne w pliku).
2. Wskaż plik CSV albo XML (do 1000 wierszy). Obok jest tabela rozpoznawanych kolumn i **wzór pliku** do pobrania.
3. **Sprawdź plik** — podgląd pokazuje dla każdego wiersza: *Nowy*, *Aktualizacja* (z listą zmienianych pól), *Bez zmian*, *Pominięty* albo *Błąd* z komunikatem przy polu. Rozpoznane kolumny są zaznaczone na zielono, nierozpoznane na szaro (są pomijane).
4. **Importuj** zapisuje wiersze bez błędów; każdy nowy rekord trafia do historii tak samo jak wpisany ręcznie.

Podgląd wykonuje dokładnie ten sam zapis co import i wycofuje go na końcu, więc pokazuje wynik, a nie prognozę — po samym podglądzie w bazie nic nie przybywa.

Co plik może zawierać: nagłówki po polsku lub angielsku w dowolnej kolejności (także „NIP”, „Data wygaśnięcia”, „Nr seryjny”), daty `RRRR-MM-DD` albo `DD.MM.RRRR`, kwoty z przecinkiem i „zł”, wartości „tak/nie”, typy i statusy kodem (`QUALIFIED_SIGNATURE`) albo etykietą („Certyfikat kwalifikowany”). Pliki zapisane przez polskiego Excela w Windows-1250 i rozdzielone przecinkami też się wczytają.

**Import wiadomości e-mail (EML).** Zapisz wiadomość z programu pocztowego jako plik `.eml` i wskaż ją w sekcji **Import wiadomości e-mail**. Aplikacja czyta nagłówki i treść (także kodowane i w załącznikach wieloczęściowych) i proponuje:

- dopasowanie **nadawcy** do użytkownika certyfikatu albo płatnika — albo dodanie nowej osoby (imię, nazwisko, e-mail i telefon z treści),
- **certyfikaty** rozpoznane po numerach seryjnych i **płatników** po NIP-ie z treści,
- oznaczenie **zaproszeń** wysłanych na adres nadawcy jako „z odpowiedzią”,
- **załączniki CSV/XML** — przycisk „Podgląd importu” wczytuje je do kreatora importu z rozpoznanym rodzajem danych.

Zaznacz działania i kliknij **Zastosuj zaznaczone**: wiadomość trafi na oś czasu wskazanych rekordów jako „Zarejestrowano wiadomość e-mail” (z tematem, nadawcą i fragmentem treści), a zaproszenia zmienią status.

### Dziennik zdarzeń (ADMIN)

Wszystkie zdarzenia systemu od najnowszych (na danych demo ok. 57): zmiany w ewidencji, zadania, wysyłki, konta, szablony, załączniki, ustawienia i przebiegi skanera. Filtry:

- **Szukaj** — nazwa certyfikatu, osoby lub płatnika, autor albo treść zdarzenia (np. adres e-mail odbiorcy),
- **Obszar** i **Zdarzenie** (z liczbą wystąpień),
- **Autor** — konto albo „System” (cron i skaner),
- **Od / Do** — zakres dat, dzień końcowy włącznie.

Kolumna „Dotyczy” prowadzi do certyfikatu, karty osoby lub płatnika, zadania albo zaproszenia. Stronicowanie po 25, 50 lub 100 zdarzeń. Dziennik jest tylko do odczytu — historii nie da się edytować ani usunąć z panelu.

### Konta i role (ADMIN)

Tabela kont: rola, stan (aktywne/wyłączone), liczba certyfikatów i otwartych zadań, ostatnie logowanie. Akcje:

- **Dodaj konto** — imię, nazwisko, e-mail, rola; osoba loguje się kodem wysłanym na ten adres,
- **Edytuj** — zmiana danych i roli,
- **Przekaż rekordy** — certyfikaty i otwarte zadania przechodzą na inne aktywne konto (np. przed odejściem pracownika); w historii każdego certyfikatu pojawia się „zmiana opiekuna”,
- **Wyłącz / Włącz** — wyłączone konto nie zaloguje się, a niewykorzystane kody przestają działać.

Zabezpieczenia: nie można wyłączyć własnego konta ani odebrać roli lub wyłączyć **ostatniego aktywnego administratora**.

---

## 5. Subskrypcje prywatne — osobna aplikacja

Do Etapu 7 ta aplikacja miała drugi panel (Netflix, Spotify, siłownia…). Od 18.09.2026 jest to
**osobny program**: „Menedżer Subskrypcji” w katalogu `menedzer_subskrypcji`, z własną bazą, własnymi
kontami i własnym interfejsem (`http://localhost/menedzer_subskrypcji/`). Jego instrukcja jest
w `menedzer_subskrypcji/docs/INSTRUKCJA.md`.

Po rozdzieleniu w tej aplikacji nie ma już kolumny `certificates.scope`, tabeli `manager_subskrypcji`
ani typów subskrypcji prywatnych — ewidencja opisuje wyłącznie certyfikaty firmowe. Dane prywatne
przeniósł jednorazowo skrypt `menedzer_subskrypcji/scripts/import-from-certisub.php` (czyta starą bazę,
nic w niej nie zmienia).

---

## 6. Jak pokazać kontrolę dostępu

Najlepszy fragment na obronę, bo różnicę widać natychmiast.

**W panelu** role zmienia ADMIN: **Konta i role → Edytuj**. Warto pokazać zabezpieczenie: próba zmiany roli własnego konta, gdy jesteś jedynym administratorem, kończy się komunikatem „W systemie musi zostać co najmniej jeden aktywny administrator”.

**Z wiersza poleceń** (żeby zobaczyć panel oczami operatora na własnym koncie):

```bash
php scripts/set-role.php mobi.litosh@gmail.com OPERATOR
```

Odśwież panel firmowy: zostaną **2 certyfikaty** (te, których jesteś opiekunem), z menu znikną **Archiwum**, **Dziennik zdarzeń** i **Konta i role**, na listach osób i płatników oraz w wyszukiwarce i kartach zostaną tylko rekordy powiązane z Twoimi certyfikatami, a przy certyfikatach nie ma przycisku **Archiwizuj**. Potem:

```bash
php scripts/set-role.php mobi.litosh@gmail.com ADMIN
```

Po odświeżeniu znów widać **10 certyfikatów**, archiwum i konta. Ponowne logowanie nie jest potrzebne — rola czytana jest z bazy przy każdym żądaniu.

Dodatkowy dowód, że to serwer pilnuje uprawnień: jako OPERATOR wywołanie `api/accounts.php`, `api/events.php` albo `api/dashboard.php?view=archive` zwraca **403** z komunikatem „Nie masz uprawnień do tej operacji”, a karta płatnika spoza zakresu (`api/reports.php?view=payer&id=…`) — **404**.

---

## 7. Zadania automatyczne (cron)

### Proces odnowień certyfikatów

```bash
php cron/renewals.php
```

Uruchamiaj raz dziennie (np. o 7:00 — Harmonogram zadań Windows albo crontab). Skrypt:

1. uruchamia **skaner odnowień** — zakłada zadania dla certyfikatów w marginesie odnowienia i podnosi priorytety zadań, których data się zbliża,
2. wysyła **zaległe przypomnienia** o zaproszeniach (wg odstępu i limitu z Ustawień).

Zaraz po `seed-demo-data.php --force` skaner znajdzie 6 certyfikatów w marginesie i założy 1 nowe zadanie; przypomnień do wysłania nie będzie (najbliższe wypada za 2 dni). Wynik trafia do `logs/renewals.log` i do historii zdarzeń. Drugie uruchomienie tego samego dnia niczego nie zdubluje.

Skrypt działa tylko z wiersza poleceń — z przeglądarki zwraca 403. Przypomnienia o płatnościach
prywatnych przeszły razem z panelem do osobnej aplikacji (`menedzer_subskrypcji/cron/reminders.php`).

### Poczta bez serwera SMTP

W `config/mail.local.php` można ustawić `'driver' => 'log'`: wiadomości (także z nazwami załączników) trafiają wtedy do `logs/mail.log` zamiast do odbiorców. To wygodne na pokaz bez internetu albo w sieci wewnętrznej — ale nie w produkcji, bo do pliku trafiają też kody logowania.

---

## 8. Model danych w bazie — jak go pokazać

W **HeidiSQL** (Laragon → Database → baza `assistent_subscriptions`) — materiał do rozdziału o projekcie bazy danych.

| Tabela | Co zawiera na danych demo |
|---|---|
| `certificates` | 12 pozycji (10 bieżących, 2 w archiwum); numery seryjne, wystawcy, daty ważności, wymagany czas odnowienia |
| `beneficiaries` | 5 użytkowników certyfikatów powiązanych z płatnikami; `created_by_user_id` — kto wprowadził rekord |
| `payers` | 4 płatników z NIP-em, adresem i kontaktem; `created_by_user_id` |
| `users` | konta personelu z rolą; `deactivated_at` — konto wyłączone przez administratora |
| `renewal_tasks` | 7 zadań: do zrobienia 3, w toku 2, zrobione 1, porzucone 1 |
| `email_templates` | 4 szablony: zaproszenie i przypomnienie, PL i EN, z polami `{imie}`, `{numer_seryjny}`, `{data_waznosci}`… |
| `attachments` | 1 załącznik (instrukcja odnowienia) — plik w `storage/attachments`, niedostępny z przeglądarki |
| `invitations` | 4 zaproszenia: wysłane z 2 przypomnieniami, z odpowiedzią, nieudane (z treścią błędu), zamknięte |
| `events` | ok. 55 zdarzeń historii; każda zmiana wykonana w panelu, skaner i wysyłki dopisują kolejne (np. `payload.changes` ze zmienionymi polami); w panelu to **Dziennik zdarzeń** i osie czasu na kartach |
| `settings` | tylko zmienione ustawienia procesu odnowień (pusta = wartości domyślne) |

Gotowe zapytania do pokazania:

```sql
-- Statystyki realizacji zadań wg statusów
SELECT status, COUNT(*) AS zadania FROM renewal_tasks GROUP BY status;

-- Oś czasu certyfikatu kwalifikowanego Jana Kowalskiego
SELECT e.occurred_at, e.entity_type, e.event_type, e.payload
FROM events e
JOIN certificates c ON c.id = e.certificate_id
WHERE c.serial_number = '5A3F9C21B7E04D18'
ORDER BY e.occurred_at;

-- Perspektywa płatnika: jego certyfikaty, terminy i osoby
SELECT c.name, c.expiry_date, CONCAT(b.first_name, ' ', b.last_name) AS uzytkownik
FROM certificates c
JOIN payers p ON p.id = c.payer_id
LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id
WHERE p.company_name = 'NovaTech Sp. z o.o.' AND c.archived_at IS NULL
ORDER BY c.expiry_date;

-- Ostatnie zmiany wykonane w panelu (kto, co, kiedy)
SELECT e.occurred_at, CONCAT(u.first_name, ' ', u.last_name) AS kto, e.entity_type, e.event_type, e.payload
FROM events e LEFT JOIN users u ON u.id = e.user_id
ORDER BY e.occurred_at DESC LIMIT 20;
```

Integralność pilnowana przez bazę — ta próba **musi się nie udać** (błąd `Duplicate entry`), bo certyfikat może mieć tylko jedno otwarte zadanie:

```sql
INSERT INTO renewal_tasks (certificate_id, status, due_date)
SELECT certificate_id, 'todo', CURDATE() FROM renewal_tasks WHERE status = 'in_progress' LIMIT 1;
```

---

## 9. Narzędzia z wiersza poleceń

| Komenda | Działanie |
|---|---|
| `php scripts/migrate.php` | migracje bazy (idempotentne) — po każdej aktualizacji |
| `php scripts/migrate.php --fresh` | **usuwa wszystkie tabele** i instaluje schemat od zera — tylko na nowej lub testowej bazie |
| `php scripts/seed-demo-data.php [--force] [--owner=e-mail]` | dane demo: certyfikaty, użytkownicy certyfikatów, płatnicy, konta demo, archiwum, zadania, zaproszenia, historia |
| `php scripts/cleanup-demo-data.php` | usuwa **wszystkie** dane biznesowe oraz konta `@example.com`; zostawia prawdziwe konta i szablony |
| `php scripts/set-role.php --list` / `<e-mail> <rola>` | lista kont / nadanie roli (awaryjnie — zwykle robi to ADMIN w panelu) |
| `php scripts/test-mail.php adres@example.com` | test konfiguracji poczty |
| `php cron/renewals.php` | skaner odnowień i zaległe przypomnienia o zaproszeniach (codziennie) |
| `composer test` | 153 testy (w tym scenariusz E2E przez API); 81 integracyjnych wymaga `RUN_INTEGRATION_TESTS=1` i tworzy osobną bazę `assistent_subscriptions_test` |
| `npm install && npm run css` | przebudowanie arkusza stylów `assets/css/app.css` po zmianie klas Tailwinda (potrzebne tylko przy zmianach w interfejsie) |
| `composer stan` | analiza statyczna (PHPStan) |

---

## 10. Scenariusz demonstracji (ok. 30 minut)

1. Laragon → Start All, `php scripts/migrate.php`, `php scripts/seed-demo-data.php --force`.
2. Strona główna → `/register.php` przekierowuje do logowania: konta zakłada administrator.
3. Logowanie: e-mail → kod z Mailtrapa → panel firmowy.
4. Pulpit: cztery karty KPI, kafelki statusów, **statystyki realizacji zadań** (statusy, skuteczność, rozkład wg osób), priorytetowe odnowienia i płatności.
5. **Lista ToDo** → **Uruchom skaner** → komunikat „nowe zadania: 1” i nowe zadanie Microsoft 365 na liście.
6. Otwórz to zadanie → **Rozpocznij** → w szczegółach certyfikatu status „odnowienie w toku”.
7. **Wyślij zaproszenie** → odbiorca: płatnik Grupa Wisła, szablon PL, załącznik z instrukcją → podgląd wiadomości z wypełnionymi polami → **Wyślij** (w trybie `sandbox` wiadomość trafia do Mailtrapa; bez internetu ustaw sterownik `log`, §7).
8. **Zaproszenia** → nowa wysyłka z terminem przypomnienia; nieudana wysyłka SSL Wildcard z błędem SMTP → **Ponów**; zaproszenie Jana Kowalskiego z 2 przypomnieniami → **Odpowiedziano**.
9. Wróć do zadania certyfikatu Jana Kowalskiego → **Odnów certyfikat** (nowa data za 2 lata) → nowy certyfikat w ewidencji, stary w archiwum, w historii łańcuch odnowień.
10. **Szablony i załączniki** (ADMIN) → edycja zaproszenia PL → **Podgląd**; zakładka Załączniki — plik w użyciu nie daje się usunąć.
11. **Ustawienia** (ADMIN) → progi i przypomnienia; terminal: `php cron/renewals.php` → wynik w `logs/renewals.log`.
12. Certyfikaty → **Dodaj certyfikat**: wybierz osobę (płatnik podpowiada się sam), wpisz „ważny od” późniejszy niż wygaśnięcie → błąd przy polu; popraw → zapis → nowy wiersz na liście i zdarzenie „Dodano certyfikat” w historii.
13. **Edytuj** ten certyfikat (np. datę wygaśnięcia) → w historii pojawia się zmiana „z → na”.
14. Płatnicy → **Dodaj płatnika** z NIP-em `987-654-32-10` → komunikat o istniejącym płatniku Grupa Wisła z odnośnikiem; **Archiwizuj** NovaTech → blokada (aktywne certyfikaty i osoby).
15. Certyfikaty → **Archiwizuj** certyfikat dodany w kroku 12 → Archiwum → **Przywróć**.
16. **Wyszukiwarka** (Ctrl+K): `nova` → płatnik, jego osoby i certyfikaty z opisem „przez powiązanie”; `634-285-19` → płatnik po NIP-ie; Enter → pełna lista wyników.
17. **Raporty** → perspektywa Użytkownika: karta Jana Kowalskiego — „odnowienie od”, ścieżka realizacji, zaproszenia, oś czasu z filtrem „Zadania” → **Drukuj**.
18. Perspektywa Płatnika: karta NovaTech — powiązane osoby, harmonogram wygaśnięć z wykresem, koszt roczny; w „Raportach” harmonogram całej organizacji z filtrem płatnika.
19. **Dziennik zdarzeń** (ADMIN) → filtr autora „System” (skaner), potem wyszukiwanie adresu e-mail odbiorcy zaproszenia.
20. **Import i eksport** → pobierz płatników w CSV (otwórz w Excelu) i XML; potem wczytaj plik z trzema wierszami: istniejący płatnik (*Pominięty*), nowy poprawny (*Nowy*), błędny NIP (*Błąd*) → zaimportuj i pokaż nowego płatnika na liście.
21. **Import wiadomości EML** → wiadomość od Jana Kowalskiego z numerem seryjnym i NIP-em w treści → rozpoznany nadawca, certyfikat, płatnik i otwarte zaproszenie; zastosuj: zaproszenie „z odpowiedzią” i wpis na osi czasu certyfikatu.
22. Konta i role → **Dodaj konto** (np. nowy OPERATOR), wyłączone konto Adama Nowickiego, okno **Przekaż rekordy**.
23. Demonstracja ról (§6): `set-role.php … OPERATOR` → 2 certyfikaty, brak archiwum, dziennika, szablonów, kont, ustawień i importu, karty i wyszukiwarka tylko w zakresie operatora → powrót do ADMIN.
24. Przełącznik języka (PL → EN) — interfejs, komunikaty walidacji i opisy zdarzeń się tłumaczą.
25. HeidiSQL: tabele modelu, zapytanie „ostatnie zmiany wykonane w panelu” (§8), nieudana próba drugiego otwartego zadania.
26. Bezpieczeństwo: `/.git/config`, `/storage/attachments/`, `/classes/Rbac.php` → **403**; `/api/accounts.php` bez logowania → **401**.

---

## 11. Czego jeszcze nie ma (uczciwa lista)

Pełna macierz jest w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md) §3. Najważniejsze braki widoczne podczas demonstracji:

- testy w prawdziwej przeglądarce (Selenium/Playwright) — scenariusz E2E idzie przez API,
- osobne logowanie dla użytkowników certyfikatów i płatników (kierunek rozwoju, decyzja D2).

---

## 12. Rozwiązywanie problemów

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| Strona się nie otwiera | Apache nie działa → Laragon → Start All |
| „Nie udało się połączyć z bazą danych” | MySQL nie działa lub brak bazy → Start All, potem `php scripts/migrate.php` |
| Błąd o brakującej tabeli lub kolumnie (np. `deactivated_at`) | kod i baza pochodzą z różnych etapów → `php scripts/migrate.php` |
| Seed: „Brak modelu danych z Etapu 1” | → `php scripts/migrate.php`, potem seed ponownie |
| Panel pusty, wszędzie zera | brak danych → `php scripts/seed-demo-data.php --force` |
| Nie widzę menu Archiwum albo Konta i role | konto ma rolę OPERATOR (archiwum od MANAGER, konta tylko ADMIN) → `php scripts/set-role.php <e-mail> ADMIN` |
| Operator nie widzi płatnika, który istnieje | tak działa zakres danych (D8): operator widzi płatników, których wprowadził albo powiązanych z jego certyfikatami → MANAGER może przypisać certyfikat |
| „Nieprawidłowy NIP” przy poprawnym numerze | NIP musi mieć poprawną sumę kontrolną; dla firm spoza Polski wpisz numer VAT UE z kodem kraju (np. `DE123456789`) |
| Nie przychodzi kod OTP | tryb `sandbox` → zajrzyj do Mailtrapa; awaryjnie kod jest w `logs/otp.log`; sprawdź też, czy konto nie jest wyłączone |
| „Zbyt wiele próśb o kod” | limit 3 wysyłek na 15 minut → odczekaj lub użyj kodu z `logs/otp.log` |
| „Sesja wygasła” w panelu | minęła sesja albo konto zostało wyłączone → zaloguj się ponownie |
| Cron nie wysyła nic | żadna płatność nie wypada dokładnie za 3 dni → `php scripts/seed-demo-data.php --force` ustawi taką pozycję |
| 403 na pliku aplikacji | tak ma być: przez HTTP dostępne są tylko strony wejściowe, `api/` i `assets/` |
| Polskie znaki jako „?” w konsoli `mysql` | kodowanie terminala Windows, dane są poprawne → pokazuj zapytania w HeidiSQL albo uruchom klienta z `--default-character-set=utf8mb4` |
| Liczniki nie zgadzają się z listą | objaw różnicy stref czasowych PHP i MySQL — naprawione w `bootstrap.php` (`Europe/Warsaw`); jeśli wróci, sprawdź strefę serwera |
| Testy integracyjne się pomijają | brak flagi → `$env:RUN_INTEGRATION_TESTS=1; composer test` (wymaga działającego MySQL) |
| Zaproszenie ma status „Nieudane” | błąd serwera pocztowego (treść w rejestrze) → sprawdź `config/mail.local.php` i połączenie, potem **Ponów**; na pokaz bez internetu ustaw `'driver' => 'log'` |
| „Wybrany odbiorca nie ma adresu e-mail” | uzupełnij e-mail osoby lub płatnika albo wybierz drugiego odbiorcę |
| Skaner nie zakłada zadania dla certyfikatu | certyfikat jest poza marginesem odnowienia, w archiwum, ma już otwarte zadanie albo zadanie dla tej daty wygaśnięcia zostało zamknięte → sprawdź „wymagany czas odnowienia” i Ustawienia |
| Nie przychodzą automatyczne przypomnienia | cron `cron/renewals.php` nie jest uruchamiany, limit przypomnień wynosi 0, zaproszenie ma odpowiedź lub zadanie jest zamknięte |
| Nie mogę dodać załącznika | niedozwolony typ, zawartość niepasująca do rozszerzenia albo plik większy niż 10 MB (komunikat podaje przyczynę) |
| Wyszukiwarka nic nie pokazuje | wpisano mniej niż 2 znaki, rekord jest poza zakresem operatora (D8) albo w archiwum (widoczne od MANAGER) |
| Karta pokazuje mniej certyfikatów niż u kolegi | OPERATOR widzi w kartach tylko swoje certyfikaty i ich zdarzenia; historia z archiwum jest od roli MANAGER |
| „Nie znaleziono” po otwarciu karty z zakładki | adres `#/payers/N` wskazuje rekord spoza zakresu konta albo z archiwum → wróć do listy |
| Wydruk karty ucina tabelę | szerokie tabele (certyfikaty z kosztem) — w oknie drukowania wybierz orientację poziomą |
| Polskie znaki w pobranym CSV są połamane | otwórz plik podwójnym kliknięciem (ma znacznik BOM) albo zaimportuj w Excelu jako UTF-8; nie zmieniaj kodowania przy zapisie |
| Import: „W pliku brakuje wymaganych kolumn” | nagłówki muszą zawierać kolumny oznaczone jako wymagane (np. nazwa, typ i data wygaśnięcia) — pobierz **wzór pliku** i skopiuj nagłówki |
| Import: „Nie znaleziono płatnika” | najpierw zaimportuj płatników, a w pliku osób i certyfikatów podaj NIP albo dokładną nazwę płatnika |
| Import: wszystkie wiersze „Pominięty” | rekordy już są w ewidencji — wybierz tryb **Aktualizuj**, aby nadpisać kolumny z pliku |
| Import EML: „To nie jest wiadomość e-mail w formacie EML” | zapisz wiadomość z programu pocztowego jako `.eml` (nie `.msg` ani zrzut ekranu) |
| Strony wyglądają „gołe”, bez stylów | brakuje `assets/css/app.css` → `npm install && npm run css` (plik jest w repozytorium, więc zwykle wystarczy pobrać projekt ponownie) |
| „Zbyt wiele próśb o kod” mimo nowego okna przeglądarki | limit liczy historię w bazie: 3 kody na adres e-mail i 10 na adres IP w 15 minutach — odczekaj albo użyj kodu z `logs/otp.log` |
| Pulpit pokazuje stare liczby | wskaźniki są liczone raz na 5 minut; każdy zapis danych je odświeża, więc wystarczy odświeżyć stronę. Jeśli to nie pomaga, sprawdź prawa zapisu do katalogu `storage/cache` (bez niego aplikacja działa, tylko liczy wskaźniki za każdym razem) |
