# CertiSub Assistant — instrukcja obsługi i scenariusz demonstracji

> Stan na 2026-09-17, po Etapie 2 (ewidencja, archiwizacja, konta i role). Opisuje aplikację na danych z `scripts/seed-demo-data.php`.
> Dokument służy dwóm celom: pokazaniu wszystkich paneli i funkcji oraz jako zalążek rozdziału „dokumentacja użytkownika” w pracy (§9 w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md)).

---

## 1. Uruchomienie

| Krok | Co zrobić |
|---|---|
| 1 | Laragon → **Start All** (Apache + MySQL). Bez tego aplikacja nie działa. |
| 2 | `php scripts/migrate.php` — po każdej aktualizacji kodu. Drugi przebieg niczego nie zmienia. |
| 3 | `php scripts/seed-demo-data.php --force` — dane do pokazania (odtwarza je od zera). |
| 4 | Otwórz `http://localhost/assistent_subscription/` |

Adresy:

| Adres | Co to |
|---|---|
| `/` | strona główna (landing) z wyborem asystenta |
| `/login.php` | logowanie kodem e-mail (OTP) |
| `/register.php` | **rejestracja wyłączona** — przekierowuje do logowania z informacją, że konta zakłada administrator (decyzja D3) |
| `/dashboard.php` | **panel firmowy** — ewidencja certyfikatów, użytkowników certyfikatów i płatników |
| `/dashboard-personal.php` | **panel prywatny** — moduł dodatkowy (streaming, muzyka, gry…), zamrożony |
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
| ADMIN | wszystko, co MANAGER, oraz konta i role (zakładanie, zmiana roli, wyłączanie, przekazywanie rekordów) |
| MANAGER | dane całej organizacji, archiwizacja i przywracanie, ekran **Archiwum**, wybór opiekuna certyfikatu |
| OPERATOR | ewidencja w swoim zakresie: certyfikaty, których jest opiekunem (albo ma do nich przydzielone zadanie), oraz osoby i płatnicy, których sam wprowadził lub którzy są z tymi certyfikatami powiązani; nie archiwizuje i nie widzi archiwum |

Kontrolę dostępu wykonuje serwer (API) — ukryte przyciski w przeglądarce to tylko wygoda. Szczegóły reguły zakresu danych: decyzja **D8** w mapie projektu.

Konta na danych demo:

| E-mail | Rola | Uwaga |
|---|---|---|
| `mobi.litosh@gmail.com` | ADMIN | Twoje konto do demonstracji — opiekun 2 aktywnych certyfikatów firmowych i 5 pozycji prywatnych |
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

Układ: nagłówek (asystent, przełącznik panelu prywatnego, język, konto, wylogowanie), menu po lewej, widok w środku. Adres widoku jest w pasku adresu (np. `dashboard.php#/payers`), więc odświeżenie strony zostawia Cię w tym samym miejscu. Kliknięcie wiersza otwiera **panel szczegółów** z prawej strony.

### Pulpit

Na danych demo (jako ADMIN):

| Element | Co pokazuje | Na danych demo |
|---|---|---|
| Karta „Certyfikaty w ewidencji” | liczba bieżących certyfikatów + aktywne/wygasłe | **10**, z tego 7 aktywnych i 1 wygasły |
| Karta „Odnowienia ≤ 30 dni” | certyfikaty w progu ostrzeżenia | **5**, z tego 2 krytyczne (≤ 7 dni) |
| Karta „Płatności do obsługi” | wkrótce + zaległe | **5**, w tym 1 zaległa |
| Karta „Zobowiązanie roczne” | suma kosztów | **33 448,00 PLN** |
| Kafelki statusów | oczekujące / odnowienie w toku / aktywne / wygasłe | 1 / 1 / 7 / 1 |
| „Priorytetowe odnowienia” | najpilniejsze certyfikaty, sortowane po dniach | na górze wygasły certyfikat kwalifikowany Jana Kowalskiego |
| „Płatności wymagające działania” | płatności wkrótce i zaległe + suma | 5 pozycji, **11 579,00 PLN** |

Kolory wierszy: czerwony = wygasłe lub krytyczne, żółty = ostrzeżenie.

### Lista ToDo

Certyfikaty wymagające działania: wygasłe, w progu odnowienia (≤ 30 dni), z zaległą płatnością albo oczekujące. W Etapie 3 zastąpią ją zadania odnowień ze statusami i przydziałem.

### Certyfikaty

- **Lista** z wyszukiwarką (nazwa, numer seryjny, wystawca, osoba, płatnik, opiekun) i filtrami: typ, status, płatność, priorytet.
- **Szczegóły** (kliknij wiersz): typ, status, numer seryjny, wystawca, ważność z liczbą dni, wymagany czas odnowienia, opiekun, koszt, płatność, notatki; odnośniki do osoby i płatnika; łańcuch odnowień (poprzedni certyfikat / zastąpiony przez); **historia zdarzeń** — kto i kiedy dodał, zmienił (z listą zmienionych pól „z → na”), zarchiwizował.
- **Dodaj / Edytuj** — formularz w sekcjach: dane certyfikatu, ważność i odnowienie, powiązania, koszt i płatność. Walidacja pokazuje błędy przy polach, np.:
  - data „ważny od” późniejsza niż wygaśnięcie,
  - numer seryjny zajęty u tego samego wystawcy,
  - nieistniejąca data (np. 31 lutego — serwer sprawdza datę kalendarzem).
- Po wybraniu osoby **płatnik podpowiada się sam**. Przyciski „+” przy polach osoby i płatnika dodają nowy rekord bez zamykania formularza.
- **Archiwizuj** (MANAGER, ADMIN) — certyfikat znika z bieżących list, historia zostaje, a otwarte zadanie odnowienia zamyka się jako porzucone.

### Użytkownicy certyfikatów

Lista osób z płatnikiem, liczbą certyfikatów i najbliższym wygaśnięciem. Szczegóły osoby pokazują jej certyfikaty; z panelu można dodać certyfikat od razu przypisany do tej osoby. Osoby z aktywnymi certyfikatami **nie da się zarchiwizować** — najpierw trzeba zarchiwizować certyfikaty albo przypisać je komuś innemu.

### Płatnicy

Lista z NIP-em, osobą kontaktową, miejscowością, liczbą osób i certyfikatów oraz kosztem rocznym. Szczegóły płatnika pokazują powiązane osoby i certyfikaty z sumą kosztów.

- **NIP** jest sprawdzany sumą kontrolną (można wpisać go z kreskami lub z prefiksem PL) i musi być unikalny. Przy próbie dodania istniejącego NIP-u formularz pokazuje nazwę istniejącego płatnika i odnośnik do niego — operator, który tego płatnika nie widzi, dostaje komunikat bez szczegółów.
- Płatnika z aktywnymi certyfikatami lub osobami **nie da się zarchiwizować** (komunikat podaje liczby).
- Płatnik „Budżet domowy” z panelu prywatnego nie pojawia się w ewidencji firmowej.

### Archiwum (MANAGER, ADMIN)

Trzy zakładki: certyfikaty, użytkownicy certyfikatów, płatnicy — z datą archiwizacji i przyciskiem **Przywróć**. Na danych demo są tu 2 certyfikaty (poprzedni SSL EV i porzucona domena). Certyfikatu nie da się przywrócić, jeśli jego płatnik lub osoba są w archiwum. Rekord z archiwum można obejrzeć, ale nie edytować.

### Konta i role (ADMIN)

Tabela kont: rola, stan (aktywne/wyłączone), liczba certyfikatów i otwartych zadań, ostatnie logowanie. Akcje:

- **Dodaj konto** — imię, nazwisko, e-mail, rola; osoba loguje się kodem wysłanym na ten adres,
- **Edytuj** — zmiana danych i roli,
- **Przekaż rekordy** — certyfikaty i otwarte zadania przechodzą na inne aktywne konto (np. przed odejściem pracownika); w historii każdego certyfikatu pojawia się „zmiana opiekuna”,
- **Wyłącz / Włącz** — wyłączone konto nie zaloguje się, a niewykorzystane kody przestają działać.

Zabezpieczenia: nie można wyłączyć własnego konta ani odebrać roli lub wyłączyć **ostatniego aktywnego administratora**.

---

## 5. Panel prywatny

Ten sam układ co dawniej, inne dane i inne progi alertów. Uwaga do pracy: to **moduł dodatkowy**, poza zakresem tematu (decyzja D1 — zamrożony, nierozwijany; nie dostał nowego interfejsu z Etapu 2).

| | Panel firmowy | Panel prywatny |
|---|---|---|
| Progi alertów | 30 dni ostrzeżenie, 7 dni krytyczne | 15 dni ostrzeżenie, 3 dni krytyczne |
| Karta kosztów | Zobowiązanie roczne | Wydatki miesięczne (**262,98 PLN**) |
| Typy | certyfikaty kwalifikowane, pieczęcie, SSL, podpis kodu, domeny, SaaS, wsparcie chmurowe | streaming, muzyka, gry, fitness, chmura |
| Dane demo | 10 bieżących certyfikatów + 2 w archiwum | 5 pozycji, 109,99 PLN do zapłaty |

Modal „Dodaj subskrypcję do menedżera” zapisuje przez API z walidacją daty kalendarzem.

---

## 6. Jak pokazać kontrolę dostępu

Najlepszy fragment na obronę, bo różnicę widać natychmiast.

**W panelu** role zmienia ADMIN: **Konta i role → Edytuj**. Warto pokazać zabezpieczenie: próba zmiany roli własnego konta, gdy jesteś jedynym administratorem, kończy się komunikatem „W systemie musi zostać co najmniej jeden aktywny administrator”.

**Z wiersza poleceń** (żeby zobaczyć panel oczami operatora na własnym koncie):

```bash
php scripts/set-role.php mobi.litosh@gmail.com OPERATOR
```

Odśwież panel firmowy: zostaną **2 certyfikaty** (te, których jesteś opiekunem), z menu znikną **Archiwum** i **Konta i role**, na listach osób i płatników zostaną tylko rekordy powiązane z Twoimi certyfikatami, a przy certyfikatach nie ma przycisku **Archiwizuj**. Potem:

```bash
php scripts/set-role.php mobi.litosh@gmail.com ADMIN
```

Po odświeżeniu znów widać **10 certyfikatów**, archiwum i konta. Ponowne logowanie nie jest potrzebne — rola czytana jest z bazy przy każdym żądaniu.

Dodatkowy dowód, że to serwer pilnuje uprawnień: jako OPERATOR wywołanie `api/accounts.php` albo `api/dashboard.php?view=archive` zwraca **403** z komunikatem „Nie masz uprawnień do tej operacji”.

---

## 7. Przypomnienia e-mail (cron)

```bash
php cron/send_reminders.php
```

Skrypt szuka płatności zaplanowanych **dokładnie za 3 dni** i wysyła wiadomość na adres właściciela. Zaraz po uruchomieniu seeda znajdzie **1 pozycję** (Netflix Standard) i wyśle maila do skrzynki Mailtrap. Efekt zobaczysz też w `logs/reminders.log` (linie `Cron start`, `OK →`, `Cron done`).

Uruchamianie z przeglądarki jest zablokowane (403) — skrypt działa tylko z wiersza poleceń.

Ograniczenia na dziś: przypomnienia dotyczą wyłącznie menedżera osobistego, a zaproszenia do odnowienia certyfikatów z szablonami i załącznikami to Etap 3.

---

## 8. Model danych w bazie — jak go pokazać

W **HeidiSQL** (Laragon → Database → baza `assistent_subscriptions`) — materiał do rozdziału o projekcie bazy danych.

| Tabela | Co zawiera na danych demo |
|---|---|
| `certificates` | 12 firmowych (10 bieżących, 2 w archiwum) + 5 prywatnych; numery seryjne, wystawcy, daty ważności, wymagany czas odnowienia |
| `beneficiaries` | 5 użytkowników certyfikatów powiązanych z płatnikami; `created_by_user_id` — kto wprowadził rekord |
| `payers` | 4 płatników z NIP-em, adresem i kontaktem; `created_by_user_id` |
| `users` | konta personelu z rolą; `deactivated_at` — konto wyłączone przez administratora |
| `renewal_tasks` | 7 zadań: do zrobienia 3, w toku 2, zrobione 1, porzucone 1 |
| `email_templates` | 4 szablony: zaproszenie i przypomnienie, PL i EN, z polami `{imie}`, `{numer_seryjny}`, `{data_waznosci}`… |
| `attachments` | 1 załącznik (instrukcja odnowienia) — plik w `storage/attachments`, niedostępny z przeglądarki |
| `invitations` | 4 zaproszenia: wysłane z 2 przypomnieniami, z odpowiedzią, nieudane (z treścią błędu), zamknięte |
| `events` | 54 zdarzenia historii; każda zmiana wykonana w panelu dopisuje kolejne (z polem `payload.changes`) |

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
| `php scripts/seed-demo-data.php [--force] [--owner=e-mail]` | dane demo: certyfikaty, użytkownicy certyfikatów, płatnicy, konta demo, archiwum, zadania, zaproszenia, historia, panel prywatny |
| `php scripts/cleanup-demo-data.php` | usuwa **wszystkie** dane biznesowe oraz konta `@example.com`; zostawia prawdziwe konta i szablony |
| `php scripts/set-role.php --list` / `<e-mail> <rola>` | lista kont / nadanie roli (awaryjnie — zwykle robi to ADMIN w panelu) |
| `php scripts/test-mail.php adres@example.com` | test konfiguracji poczty |
| `php cron/send_reminders.php` | przypomnienia o płatnościach |
| `composer test` | 70 testów; 26 integracyjnych wymaga `RUN_INTEGRATION_TESTS=1` i tworzy osobną bazę `assistent_subscriptions_test` |
| `composer stan` | analiza statyczna (PHPStan) |

---

## 10. Scenariusz demonstracji (ok. 15 minut)

1. Laragon → Start All, `php scripts/migrate.php`, `php scripts/seed-demo-data.php --force`.
2. Strona główna → `/register.php` przekierowuje do logowania: konta zakłada administrator.
3. Logowanie: e-mail → kod z Mailtrapa → panel firmowy.
4. Pulpit: cztery karty KPI, kafelki statusów, priorytetowe odnowienia i płatności.
5. Certyfikaty → kliknięcie wygasłego certyfikatu Jana Kowalskiego → panel szczegółów: powiązania, historia zdarzeń.
6. **Dodaj certyfikat**: wybierz osobę (płatnik podpowiada się sam), wpisz „ważny od” późniejszy niż wygaśnięcie → błąd przy polu; popraw → zapis → nowy wiersz na liście i zdarzenie „Dodano certyfikat” w historii.
7. **Edytuj** ten certyfikat (np. datę wygaśnięcia) → w historii pojawia się zmiana „z → na”.
8. Płatnicy → **Dodaj płatnika** z NIP-em `987-654-32-10` → komunikat o istniejącym płatniku Grupa Wisła z odnośnikiem.
9. Płatnicy → **Archiwizuj** NovaTech → blokada: płatnik ma aktywne certyfikaty i osoby.
10. Certyfikaty → **Archiwizuj** certyfikat dodany w kroku 6 → Archiwum → **Przywróć**.
11. Konta i role → **Dodaj konto** (np. nowy OPERATOR), pokaż wyłączone konto Adama Nowickiego i **Przekaż rekordy** Tomasza Wróbla do Ewy Pawlak (albo tylko pokaż okno).
12. Demonstracja ról (§6): `set-role.php … OPERATOR` → 2 certyfikaty, brak archiwum i kont → powrót do ADMIN.
13. Przełącznik języka (PL → EN) — cały interfejs i komunikaty walidacji się tłumaczą.
14. Przełącznik na panel prywatny (moduł dodatkowy) → modal „Dodaj subskrypcję do menedżera”.
15. HeidiSQL: tabele modelu, zapytanie „ostatnie zmiany wykonane w panelu” (§8), nieudana próba drugiego otwartego zadania.
16. Bezpieczeństwo: `/.git/config`, `/storage/attachments/`, `/classes/Rbac.php` → **403**; `/api/accounts.php` bez logowania → **401**.

---

## 11. Czego jeszcze nie ma (uczciwa lista)

Pełna macierz jest w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md) §3. Najważniejsze braki widoczne podczas demonstracji:

- **proces odnowień** — skaner tworzący zadania, ekran zadań ToDo ze statusami i przydziałem, szablony i załączniki, wysyłka zaproszeń i przypomnień, statystyki zadań (Etap 3); lista ToDo to na razie filtr certyfikatów,
- **raporty perspektyw** — karty osoby i płatnika z historią, wyszukiwarka globalna, dziennik zdarzeń administratora (Etap 4); dziś historia jest w szczegółach certyfikatu,
- **import i eksport** CSV/XML/EML (Etap 5),
- lokalne zasoby frontendu zamiast CDN, limit wysyłek kodów OTP w bazie (Etap 6),
- skeleton loadery i cache wskaźników (Etap 7).

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
