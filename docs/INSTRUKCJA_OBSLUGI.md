# CertiSub Assistant — instrukcja obsługi i scenariusz demonstracji

> Stan na 2026-09-16, po Etapie 1 (model danych). Opisuje aplikację na danych z `scripts/seed-demo-data.php`.
> Dokument służy dwóm celom: pokazaniu wszystkich paneli i funkcji oraz jako zalążek rozdziału „dokumentacja użytkownika” w pracy (§9 w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md)).

---

## 1. Uruchomienie

| Krok | Co zrobić |
|---|---|
| 1 | Laragon → **Start All** (Apache + MySQL). Bez tego aplikacja nie działa. |
| 2 | `php scripts/migrate.php` — po każdej aktualizacji kodu. Etap 1 zmienił strukturę bazy; drugi przebieg niczego nie zmienia. |
| 3 | `php scripts/seed-demo-data.php --force` — dane do pokazania (odtwarza je od zera). |
| 4 | Otwórz `http://localhost/assistent_subscription/` |

Adresy:

| Adres | Co to |
|---|---|
| `/` | strona główna (landing) z wyborem asystenta |
| `/login.php` | logowanie kodem e-mail (OTP) |
| `/register.php` | rejestracja konta (docelowo tylko przez ADMIN-a — decyzja D3) |
| `/dashboard.php` | **panel firmowy** — certyfikaty i usługi |
| `/dashboard-personal.php` | **panel prywatny** — moduł dodatkowy (streaming, muzyka, gry…) |
| `/logout.php` | wylogowanie |

Katalogi wewnętrzne (`classes/`, `config/`, `scripts/`, `storage/`, `logs/`, `.git/`…) zwracają **403** — to celowe zabezpieczenie.

Interfejs startuje po polsku; język zmienisz przełącznikiem w nagłówku.

---

## 2. Konta, role i użytkownicy certyfikatów

### Konta personelu

Hierarchia: **ADMIN > MANAGER > OPERATOR**. Rolę zmienia się z wiersza poleceń:

```bash
php scripts/set-role.php --list
```

| Rola | Co widzi w panelu |
|---|---|
| ADMIN, MANAGER | wszystkie pozycje organizacji + zakładki **Użytkownicy** i **Płatnicy** |
| OPERATOR | tylko pozycje, których jest opiekunem; zakładek Użytkownicy i Płatnicy **nie ma** |

| E-mail | Rola | Uwaga |
|---|---|---|
| `mobi.litosh@gmail.com` | ADMIN | Twoje konto do demonstracji — opiekun 2 aktywnych pozycji firmowych i 5 prywatnych |
| dwa wcześniej założone konta testowe | OPERATOR | konta istniejące przed Etapem 0 |
| `ewa.pawlak@example.com` | MANAGER | konto demo personelu — opiekunka 4 pozycji |
| `tomasz.wrobel@example.com` | OPERATOR | konto demo personelu — opiekun 4 pozycji |

Konta `@example.com` służą jako opiekunowie rekordów i osoby przypisane do zadań — nie da się na nie zalogować, bo nie ma dostępu do tych skrzynek.

### Użytkownicy certyfikatów (beneficjenci)

Od Etapu 1 to **osobna tabela** z danymi osobowymi, powiązana z płatnikiem — nie są to konta logowania:

| Osoba | Płatnik | Certyfikat |
|---|---|---|
| Jan Kowalski | NovaTech Sp. z o.o. | kwalifikowany — **wygasł** 12 dni temu |
| Maria Wójcik | NovaTech Sp. z o.o. | pieczęć kwalifikowana NovaTech — odnowienie w toku |
| Piotr Lewandowski | Grupa Wisła S.A. | kwalifikowany — ważny |
| Michał Kamiński | Grupa Wisła S.A. | brak (osoba bez certyfikatu) |
| Agnieszka Mazur | Fundacja Cyfrowy Śląsk | kwalifikowany — ważny |

Ekrany dla nich powstaną w Etapie 2 (ewidencja) i 4 (karta użytkownika). Dziś widać ich w bazie — patrz §8.

---

## 3. Logowanie (bez hasła, kodem OTP)

1. `/login.php` → podaj e-mail → **Wyślij kod**.
2. Aplikacja wysyła 6-cyfrowy kod. Konfiguracja to `driver = sandbox`, więc **kod trafia do skrzynki Mailtrap** (mailtrap.io → Email Testing → Inboxes), a **nie** na prawdziwego Gmaila.
3. Gdyby SMTP nie zadziałał, włączone `dev_log_codes` zapisuje kod do `logs/otp.log` i logowanie i tak przejdzie — to awaryjne wyjście na pokazie.
4. Wpisz kod → trafiasz do panelu. Kod jest ważny 10 minut, ma limit 5 prób i jednorazowe użycie.

Warte pokazania jako element bezpieczeństwa: przy nieistniejącym e-mailu komunikat brzmi „jeśli konto istnieje, wysłaliśmy kod” — system nie zdradza, czy konto istnieje.

---

## 4. Panel firmowy — co jest na ekranie

Na danych demo zobaczysz dokładnie te liczby (jako ADMIN):

### Pulpit

| Element | Co pokazuje | Na danych demo |
|---|---|---|
| Karta „Wszystkie subskrypcje” | liczba aktywnych pozycji + aktywne/wygasłe | **10**, z tego 7 aktywnych i 1 wygasła |
| Karta „Odnowienia ≤ 30 dni” | pozycje do odnowienia w progu ostrzeżenia | **5**, z tego 2 krytyczne (≤ 7 dni) |
| Karta „Płatności do obsługi” | wkrótce + zaległe | **5**, w tym 1 zaległa |
| Karta „Zobowiązanie roczne” | suma kosztów rocznych | **33 448,00 PLN** |
| Kafelki statusów | oczekujące / odnowienie w toku / aktywne / wygasłe | 1 / 1 / 7 / 1 |
| „Moje subskrypcje w menedżerze” | prywatne subskrypcje zalogowanej osoby | **5** pozycji |
| „Płatności wymagające działania” | pozycje wkrótce i zaległe + suma | 5 pozycji, **11 579,00 PLN** |
| „Priorytetowe odnowienia” | 6 najpilniejszych, sortowane po dniach | najpilniejszy: certyfikat kwalifikowany Jana Kowalskiego, wygasły 12 dni temu |
| „Oś czasu odnowienia” | szczegóły klikniętego wiersza: opiekun, płatnik, cena, notatka | klikaj wiersze w tabelach powyżej |

Kolory wierszy: czerwony = wygasłe lub krytyczne, żółty = ostrzeżenie. Ikony 🔴 i 🟡 powtarzają tę informację przy nazwie. Dwie pozycje z **archiwum** (poprzedni certyfikat SSL EV i porzucona domena) są w bazie, ale panel ich nie pokazuje — tak działa archiwizacja.

Panel nadal nazywa pozycje „subskrypcjami”; etykiety certyfikatów zmienią się razem z nowym UI w Etapie 2.

### Pozostałe zakładki

| Zakładka | Zawartość na danych demo |
|---|---|
| **Lista To-Do** | pozycje wymagające działania: wygasłe, w progu odnowienia, zaległe płatności, oczekujące |
| **Subskrypcje** | pełna lista 10 pozycji + wyszukiwarka i 3 filtry: typ (m.in. **Certyfikat kwalifikowany**, **Pieczęć kwalifikowana**), płatność, priorytet — wszystko natychmiast, bez przeładowania |
| **Użytkownicy** | 3 opiekunów rekordów (Ty, Ewa Pawlak, Tomasz Wróbel) + liczba pozycji (tylko ADMIN/MANAGER) |
| **Płatnicy** | 3 firmy: NovaTech, Grupa Wisła, Fundacja Cyfrowy Śląsk + liczba pozycji i suma kosztów (tylko ADMIN/MANAGER) |

### Pozostałe funkcje nagłówka

- **Przełącznik asystenta** („↔ Prywatne / Firmowe”) — przejście między panelami.
- **Wyszukiwarka globalna** — po nazwie, opiekunie, płatniku i typie.
- **Przełącznik języka** — PL (domyślny), EN, ES, DE, UK. Nowe teksty utrzymujemy w PL i EN, pozostałe języki dziedziczą EN.
- **Usuń konto** — modal z potwierdzeniem. Na koncie ADMIN pokaże **komunikat blokady**, bo konto jest opiekunem certyfikatów. To zamierzone: dane biznesowe nie giną razem z kontem.

---

## 5. Panel prywatny

Ten sam układ, inne dane i inne progi alertów. Uwaga do pracy: to **moduł dodatkowy**, poza zakresem tematu (decyzja D1 — zamrożony, nierozwijany).

| | Panel firmowy | Panel prywatny |
|---|---|---|
| Progi alertów | 30 dni ostrzeżenie, 7 dni krytyczne | 15 dni ostrzeżenie, 3 dni krytyczne |
| Karta kosztów | Zobowiązanie roczne | Wydatki miesięczne (**262,98 PLN**) |
| Typy | certyfikaty kwalifikowane, pieczęcie, SSL, podpis kodu, domeny, SaaS, wsparcie chmurowe | streaming, muzyka, gry, fitness, chmura |
| Dane demo | 10 aktywnych pozycji + 2 w archiwum | 5 pozycji (1 wygasła, 1 krytyczna, 1 ostrzeżenie, 2 spokojne), 109,99 PLN do zapłaty |

---

## 6. Jak pokazać kontrolę dostępu

Najlepszy fragment na obronę, bo różnicę widać natychmiast:

```bash
php scripts/set-role.php mobi.litosh@gmail.com OPERATOR
```

Odśwież panel firmowy: zostaną **2 pozycje** (tylko te, których jesteś opiekunem), a zakładki **Użytkownicy** i **Płatnicy** znikną z nawigacji. Potem:

```bash
php scripts/set-role.php mobi.litosh@gmail.com ADMIN
```

Po odświeżeniu znów widać **10 pozycji** i oba katalogi. Ponowne logowanie nie jest potrzebne — rola czytana jest z bazy przy każdym żądaniu.

---

## 7. Przypomnienia e-mail (cron)

```bash
php cron/send_reminders.php
```

Skrypt szuka płatności zaplanowanych **dokładnie za 3 dni** i wysyła wiadomość na adres właściciela. Zaraz po uruchomieniu seeda znajdzie **1 pozycję** (Netflix Standard) i wyśle maila do skrzynki Mailtrap. Efekt zobaczysz też w `logs/reminders.log` (linie `Cron start`, `OK →`, `Cron done`).

Uruchamianie z przeglądarki jest zablokowane (403) — skrypt działa tylko z wiersza poleceń.

Ograniczenia na dziś: przypomnienia dotyczą wyłącznie menedżera osobistego, pominięty dzień przepada, a treść jest wpisana w kodzie. Zaproszenia do odnowienia certyfikatów z szablonami i załącznikami mają już model danych (§8), a wysyłka to Etap 3.

---

## 8. Model danych w bazie (Etap 1) — jak go pokazać

Tabele nowego modelu nie mają jeszcze ekranów, ale na obronie warto je pokazać w **HeidiSQL** (Laragon → Database → baza `assistent_subscriptions`) — to materiał do rozdziału o projekcie bazy danych.

| Tabela | Co zawiera na danych demo |
|---|---|
| `certificates` | 12 firmowych (10 aktywnych, 2 w archiwum) + 5 prywatnych; numery seryjne, wystawcy, daty ważności, wymagany czas odnowienia |
| `beneficiaries` | 5 użytkowników certyfikatów powiązanych z płatnikami |
| `payers` | 4 płatników z NIP-em, adresem i kontaktem |
| `renewal_tasks` | 7 zadań: do zrobienia 3, w toku 2, zrobione 1, porzucone 1 |
| `email_templates` | 4 szablony: zaproszenie i przypomnienie, PL i EN, z polami `{imie}`, `{numer_seryjny}`, `{data_waznosci}`… |
| `attachments` | 1 załącznik (instrukcja odnowienia) — plik w `storage/attachments`, niedostępny z przeglądarki |
| `invitations` | 4 zaproszenia: wysłane z 2 przypomnieniami, z odpowiedzią, nieudane (z treścią błędu), zamknięte |
| `events` | 50 zdarzeń historii — materiał na oś czasu |

Gotowe zapytania do pokazania:

```sql
-- Statystyki realizacji zadań wg statusów
SELECT status, COUNT(*) AS zadania FROM renewal_tasks GROUP BY status;

-- Oś czasu certyfikatu kwalifikowanego Jana Kowalskiego
SELECT e.occurred_at, e.entity_type, e.event_type
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

-- Łańcuch odnowień: nowy certyfikat i ten, który zastąpił
SELECT nowy.name AS nowy, stary.name AS poprzedni, stary.archived_at
FROM certificates nowy
JOIN certificates stary ON stary.id = nowy.previous_certificate_id;
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
| `php scripts/seed-demo-data.php [--force] [--owner=e-mail]` | dane demo: certyfikaty, użytkownicy certyfikatów, płatnicy, archiwum, zadania, zaproszenia, historia, panel prywatny |
| `php scripts/cleanup-demo-data.php` | usuwa **wszystkie** dane biznesowe (certyfikaty, osoby, płatników, zadania, zaproszenia, historię) oraz konta `@example.com`; zostawia prawdziwe konta i szablony |
| `php scripts/set-role.php --list` / `<e-mail> <rola>` | lista kont / nadanie roli |
| `php scripts/test-mail.php adres@example.com` | test konfiguracji poczty |
| `php cron/send_reminders.php` | przypomnienia o płatnościach |
| `composer test` / `composer stan` | 28 testów (2 integracyjne wymagają `RUN_INTEGRATION_TESTS=1`) / analiza statyczna |

---

## 10. Scenariusz demonstracji (ok. 12 minut)

1. Laragon → Start All, `php scripts/migrate.php`, `php scripts/seed-demo-data.php --force`.
2. Strona główna — dwa asystenty, co system śledzi, jak działa.
3. Logowanie: e-mail → kod z Mailtrapa → panel.
4. Pulpit firmowy: cztery karty KPI, kafelki statusów, kolory priorytetów.
5. Kliknięcie wygasłego certyfikatu kwalifikowanego → oś czasu: opiekun, płatnik, cena, notatka.
6. Płatności wymagające działania → suma 11 579,00 PLN.
7. Lista To-Do → pozycje wymagające działania.
8. Subskrypcje → filtr typu „Certyfikat kwalifikowany”, potem filtr priorytetu „Krytyczne”.
9. Użytkownicy i Płatnicy → kto opiekuje się pozycjami, które firmy za nie płacą.
10. Przełącznik języka (PL → EN) — cały interfejs się tłumaczy.
11. Demonstracja ról: `set-role.php … OPERATOR` → 2 pozycje i brak katalogów → powrót do ADMIN.
12. Przełącznik na panel prywatny → inne progi, wydatki miesięczne (moduł dodatkowy).
13. Modal „Dodaj subskrypcję do menedżera” → zapis przez API (CSRF + walidacja) → nowy wiersz bez przeładowania strony.
14. Terminal: `php cron/send_reminders.php` → 1 wysyłka → wiadomość w Mailtrapie i wpis w `logs/reminders.log`.
15. HeidiSQL: tabele modelu, zapytanie o statystyki zadań i oś czasu Jana Kowalskiego, nieudana próba drugiego otwartego zadania (§8).
16. Bezpieczeństwo: `http://localhost/assistent_subscription/.git/config` i `/storage/attachments/` → **403**.

---

## 11. Czego jeszcze nie ma (uczciwa lista)

Pełna macierz jest w [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md) §3. Najważniejsze braki widoczne podczas demonstracji:

- **brak ekranów ewidencji** — dodawania, edycji i archiwizacji certyfikatów, użytkowników certyfikatów i płatników; model danych jest gotowy, interfejs to Etap 2. Jedyny formularz zapisu to modal menedżera osobistego,
- zadania ToDo, szablony, załączniki, zaproszenia i historia istnieją w bazie, ale nie mają ekranów ani wysyłki (Etapy 3–4) — lista To-Do w panelu to nadal filtr,
- brak importu i eksportu CSV/XML/EML (Etap 5),
- brak kart raportowych użytkownika certyfikatu i płatnika oraz wyszukiwarki po wszystkich encjach (Etap 4),
- panel nazywa pozycje „subskrypcjami” — etykiety certyfikatów przyjdą z nowym UI (Etap 2),
- rejestracja jest wciąż publiczna (docelowo konta zakłada ADMIN — decyzja D3, Etap 2).

---

## 12. Rozwiązywanie problemów

| Objaw | Przyczyna i rozwiązanie |
|---|---|
| Strona się nie otwiera | Apache nie działa → Laragon → Start All |
| „Nie udało się połączyć z bazą danych” | MySQL nie działa lub brak bazy → Start All, potem `php scripts/migrate.php` |
| Błąd o brakującej tabeli `certificates` lub `subscriptions` | kod i baza pochodzą z różnych etapów → `php scripts/migrate.php` |
| Seed: „Brak modelu danych z Etapu 1” | → `php scripts/migrate.php`, potem seed ponownie |
| Panel pusty, wszędzie zera | brak danych → `php scripts/seed-demo-data.php --force` |
| Nie widzę zakładek Użytkownicy/Płatnicy | konto ma rolę OPERATOR → `php scripts/set-role.php <e-mail> ADMIN` |
| Nie przychodzi kod OTP | tryb `sandbox` → zajrzyj do Mailtrapa; awaryjnie kod jest w `logs/otp.log` |
| „Zbyt wiele próśb o kod” | limit 3 wysyłek na 15 minut → odczekaj lub użyj kodu z `logs/otp.log` |
| Cron nie wysyła nic | żadna płatność nie wypada dokładnie za 3 dni → `php scripts/seed-demo-data.php --force` ustawi taką pozycję |
| 403 na pliku aplikacji | tak ma być: przez HTTP dostępne są tylko strony wejściowe i `api/` |
| Polskie znaki jako „?” w konsoli `mysql` | kodowanie terminala Windows, dane są poprawne → pokazuj zapytania w HeidiSQL albo uruchom klienta z `--default-character-set=utf8mb4` |
| Liczniki nie zgadzają się z listą | objaw różnicy stref czasowych PHP i MySQL — naprawione w `bootstrap.php` (`Europe/Warsaw`); jeśli wróci, sprawdź strefę serwera |
