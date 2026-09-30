# CertiSub Assistant

Praca inżynierska (Uniwersytet Śląski) — webowa aplikacja LAMP do ewidencji **certyfikatów** (m.in. kwalifikowanych podpisów i pieczęci, certyfikatów SSL, code signing, domen, usług SaaS) wraz z ich **użytkownikami (beneficjentami)** i **płatnikami**, obejmująca cały proces odnowienia: skanowanie terminów, zadania ToDo, zaproszenia e-mail z szablonami i załącznikami, przypomnienia, archiwizację, historię zdarzeń, raporty oraz wymianę danych (CSV/XML/EML).

## Cel projektu i tło akademickie

Zgodnie z oficjalnym opisem pracy dyplomowej, celem systemu jest wspieranie administratora w nadzorze nad certyfikatami wymagającymi cyklicznego odnowienia. Aplikacja wiąże ze sobą trzy typy informacji — **certyfikat** (dane usługi: numer seryjny, daty ważności i wygaśnięcia, wymagany margines odnowienia), **użytkownika certyfikatu** (dane osobowe beneficjenta) i **płatnika** (podmiot rozliczający usługę) — i na tej podstawie udostępnia raportowanie w trzech perspektywach:

- **Użytkownik certyfikatu** — daty odnowienia, szczegóły certyfikatu, historia zdarzeń.
- **Płatnik** — lista certyfikatów, terminy wygaśnięcia, powiązane osoby, historia.
- **Administrator** — zarządzanie całym systemem niezależnie od perspektyw.

System w sposób cykliczny skanuje bazę danych, wyszukuje certyfikaty zbliżające się do terminu odnowienia w założonym marginesie czasowym, tworzy priorytetyzowaną listę zadań „ToDo”, wysyła zaproszenia do odnowienia e-mailem (szablon + załączniki) i zarządza przypomnieniami — a każdy krok tego procesu zapisywany jest w historii zdarzeń. Dostęp do systemu jest oparty o hierarchię kont z podziałem na role (ADMIN > MANAGER > OPERATOR), a dane podlegają archiwizacji zamiast trwałego usuwania. Dodatkowo aplikacja umożliwia eksport danych do CSV/XML oraz import z plików CSV/XML/EML.

Choć opis pracy wskazywał wstępnie Javę ze Spring jako narzędzie realizacji, po analizie dostępnych opcji (studium wykonalności) do budowy warstwy webowej wybrano **PHP** w architekturze **LAMP**, zgodnie z częścią opisu dopuszczającą tę technologię dla jądra aplikacji bazodanowej.

Pełna dokumentacja procesu powstawania projektu — oficjalny opis pracy, macierz wymagań, model danych, plan etapów i dziennik zmian — znajduje się w [`docs/MAPA_PROJEKTU.md`](docs/MAPA_PROJEKTU.md). Instrukcja obsługi i scenariusz demonstracyjny: [`docs/INSTRUKCJA_OBSLUGI.md`](docs/INSTRUKCJA_OBSLUGI.md).

## Wersja demonstracyjna (online)

Aplikacja działa też jako publiczny link, bez uruchamiania czegokolwiek lokalnie — wystarczy przeglądarka na dowolnym urządzeniu. Instrukcja wdrożenia na darmowy hosting PHP+MySQL: [`docs/DEPLOY_INFINITYFREE.md`](docs/DEPLOY_INFINITYFREE.md).

<!-- Po wdrożeniu: **Demo:** https://twoja-domena/ -->

## Stack technologiczny

- **Backend:** PHP 8.1+ (rozwijane na 8.3), architektura bez frameworka — własna warstwa usług (`App\Service`) i kontroler HTTP (`App\Http\ApiKernel`), PDO z natywnymi zapytaniami przygotowanymi (prepared statements)
- **Baza danych:** MySQL 8 / MariaDB 10.5+ (silnik InnoDB, `utf8mb4`)
- **Frontend:** Vue 3 bez kroku budowania (ładowany bezpośrednio, bez CDN — biblioteka wendorowana w repozytorium), Tailwind CSS 3 budowany lokalnie (`npm run css`), czcionka Inter dołączona do repozytorium — aplikacja działa również bez dostępu do internetu (intranet)
- **Poczta:** PHPMailer (SMTP/Sandbox Mailtrap lub Gmail) albo sterownik `log` zapisujący wiadomości do pliku na potrzeby demo
- **Serwer WWW:** Apache z `mod_rewrite` i `AllowOverride All` (np. Laragon) — `.htaccess` blokuje katalogi wewnętrzne
- **Jakość i testy:** PHPUnit 11 (testy jednostkowe + integracyjne na osobnej bazie), PHPStan (poziom 5), PHP-CS-Fixer (@PSR12)
- **i18n:** PL (domyślny), EN, ES, DE, UK

## Instrukcja uruchomienia

### Wymagania wstępne

- PHP 8.1+ z rozszerzeniami PDO/MySQL
- MySQL 8 lub MariaDB 10.5+
- Composer
- Apache z `mod_rewrite` i `AllowOverride All` (np. środowisko Laragon) — projekt zakłada, że katalog aplikacji jest jednocześnie webroot
- Node.js — opcjonalnie, tylko do przebudowy arkusza stylów po zmianach w klasach Tailwind (zbudowany `assets/css/app.css` jest już w repozytorium)

### Instalacja zależności

```powershell
composer install
```

### Konfiguracja

- `config/database.php` — dane połączenia z MySQL (`host`, `dbname`, `username`, `password`, `charset`); domyślnie `localhost` / `assistent_subscriptions` / `root` bez hasła (typowe dla Laragon)
- `config/mail.local.php` — konfiguracja poczty, **plik nieśledzony przez git** (sekrety); utworzyć na podstawie wzorca:

```powershell
copy config\mail.local.php.example config\mail.local.php
```

  - sterownik `sandbox` — wiadomości trafiają wyłącznie na [mailtrap.io](https://mailtrap.io) (bezpieczne do testów)
  - sterownik `smtp` — rzeczywista wysyłka (hasło aplikacji Gmail albo Mailtrap Email Sending)
  - sterownik `log` — wiadomości zapisywane do `logs/mail.log`, bez wysyłki (wygodne na intranecie bez serwera SMTP)

### Baza danych

Nowa baza:

```powershell
php scripts\migrate.php --fresh
```

`--fresh` **usuwa wszystkie tabele**, importuje `database/schema.sql` i uruchamia migracje (m.in. domyślne szablony wiadomości) — używać wyłącznie na nowej lub tymczasowej bazie.

Istniejąca baza (np. po aktualizacji kodu):

```powershell
php scripts\migrate.php
```

Migracje są idempotentne — bezpiecznie uruchamiać je po każdej aktualizacji.

### Dane demonstracyjne (opcjonalnie)

```powershell
php scripts\seed-demo-data.php
```

Wypełnia panel certyfikatami we wszystkich priorytetach, beneficjentami, płatnikami, archiwum z łańcuchem odnowień, zadaniami ToDo w każdym statusie, zaproszeniami z załącznikiem i historią zdarzeń. Ponowne uruchomienie z `--force` odtwarza dane od nowa; `php scripts\cleanup-demo-data.php` usuwa wszystkie dane biznesowe.

### Pierwsze konto administratora

Rejestracja publiczna jest wyłączona — konta zakłada ADMIN w panelu (Zarządzanie → Konta i role). Aby nadać pierwszej osobie rolę ADMIN:

```powershell
php scripts\set-role.php you@example.com ADMIN
```

### Uruchomienie aplikacji

Po skopiowaniu katalogu do webroot serwera (np. `C:\laragon\www\assistent_subscription`) i uruchomieniu usług Apache + MySQL:

- Strona główna: `http://localhost/assistent_subscription/`
- Panel: `http://localhost/assistent_subscription/dashboard.php`

### Zadanie cykliczne (cron)

```powershell
php cron\renewals.php
```

Skaner odnowień (zakłada zadania ToDo) oraz wysyłka zaległych przypomnień o zaproszeniach — uruchamiać codziennie (np. Harmonogram zadań Windows: program = pełna ścieżka do `php.exe`, argument = pełna ścieżka do skryptu). Wynik zapisywany do `logs/renewals.log`.

### Testy i statyczna analiza

```powershell
composer test                                    # PHPUnit — testy jednostkowe
$env:RUN_INTEGRATION_TESTS=1; composer test      # + testy integracyjne (tymczasowa baza assistent_subscriptions_test)
composer stan                                    # PHPStan (poziom 5)
composer cs                                      # PHP-CS-Fixer (tryb dry-run)
```

Konto MySQL z `config/database.php` musi mieć uprawnienia do tworzenia i usuwania bazy testowej.

## Struktura katalogów i kluczowe moduły

```
assistent_subscription/
├── index.php, login.php, register.php, logout.php   # wejście, logowanie (e-mail + hasło), rejestracja, wylogowanie
├── verify-email.php, set-password.php               # potwierdzenie adresu i ustawienie hasła linkiem z wiadomości
├── dashboard.php                                     # punkt wejścia panelu (Vue)
├── api/                     # cienkie endpointy JSON — logika w classes/Api
├── classes/
│   ├── Service/             # reguły biznesowe: walidacja, uprawnienia (Rbac), zakres danych operatora, historia zdarzeń
│   ├── Http/                # ApiKernel (CSRF, sesja, mapowanie wyjątków na kody HTTP), Request, Response
│   ├── Api/                 # kontrolery obsługujące poszczególne endpointy
│   ├── Exchange/             # formaty wymiany danych: czytniki/zapisy CSV i XML, parser EML, aliasy kolumn
│   └── Migrations/           # idempotentne migracje schematu bazy
├── includes/                # powłoka panelu, wspólny <head>, przełącznik języka
├── assets/                  # css (build Tailwind), js (komponenty Vue bez kroku budowania), fonty, vendor
├── cron/                    # renewals.php — skaner odnowień + przypomnienia o zaproszeniach
├── database/schema.sql      # pełny schemat do świeżej instalacji
├── scripts/                 # migrate, seed-demo-data, cleanup-demo-data, set-role, test-mail
├── storage/                 # attachments/ (załączniki) i cache/ (agregaty pulpitu) — niedostępne przez HTTP
├── docs/                    # mapa projektu, instrukcja obsługi, materiały pracy dyplomowej
└── tests/                   # PHPUnit — testy jednostkowe i integracyjne
```

**Model danych:** `certificates`, `beneficiaries` (użytkownicy certyfikatów), `payers`, `users` (konta personelu), `renewal_tasks` (zadania ToDo), `email_templates` + `attachments` + `email_template_attachments`, `invitations` + `invitation_attachments`, `events` (historia/oś czasu), `settings` (progi odnowienia), `email_verifications` (jednorazowe tokeny z wiadomości e-mail), `login_attempts` (limit prób logowania), `login_otps` (historia logowań sprzed Etapu 9). Szczegóły: `docs/MAPA_PROJEKTU.md` §2.2.

## API i kluczowe funkcje

Wszystkie endpointy zwracają JSON, wymagają aktywnej sesji oraz (dla zapisów) nagłówka `X-CSRF-TOKEN`; kontrola dostępu egzekwowana jest po stronie API zgodnie z hierarchią ról ADMIN > MANAGER > OPERATOR (`App\Rbac`).

| Endpoint | Zakres odpowiedzialności |
|---|---|
| `api/certificates.php` | CRUD certyfikatów, walidacja (m.in. suma kontrolna NIP, unikalny numer seryjny u wystawcy), odnowienie (nowy rekord + archiwizacja starego) |
| `api/beneficiaries.php` | CRUD użytkowników certyfikatów (beneficjentów) |
| `api/payers.php` | CRUD płatników wraz z kosztem rocznym |
| `api/tasks.php` | Lista ToDo, zmiana statusu, przydział (MANAGER+), porzucenie z powodem |
| `api/invitations.php` | Wysyłka zaproszeń e-mail z szablonu, rejestr, ponowienie, przypomnienia |
| `api/templates.php` | Szablony wiadomości (PL/EN) i biblioteka załączników (ADMIN) |
| `api/reports.php` | Karty raportowe (perspektywa użytkownika certyfikatu / płatnika), harmonogram wygaśnięć |
| `api/search.php` | Wyszukiwarka globalna (Ctrl+K) po certyfikatach, osobach i płatnikach wraz z powiązaniami |
| `api/events.php` | Oś czasu rekordu oraz dziennik zdarzeń administratora |
| `api/export.php` | Eksport list, kart raportowych, harmonogramu i dziennika zdarzeń do CSV/XML |
| `api/import.php` | Import płatników/osób/certyfikatów z CSV/XML oraz wiadomości e-mail (EML) z podglądem wiersz po wierszu przed zapisem (ADMIN) |
| `api/accounts.php`, `api/delete_account.php` | Konta personelu, role, dezaktywacja/usunięcie (ADMIN) |
| `api/settings.php` | Progi i parametry procesu odnowienia (ADMIN) |
| `api/dashboard.php` | Zagregowane KPI pulpitu (cache’owane per rola/właściciel) |
| `api/attachments.php` | Biblioteka plików dla szablonów i zaproszeń |

**Kluczowe funkcje aplikacji:**

- Logowanie adresem e-mail i hasłem (`password_hash()`), adres potwierdzany jednorazowym linkiem z wiadomości wysyłanej przez SMTP z autoryzacją OAuth2; ochrona CSRF, limit prób logowania; konta dezaktywowane i przypisywane przez ADMIN-a (rejestracja publiczna jest przełącznikiem w `config/auth.php`)
- Uprawnienia oparte o role z zakresem danych operatora (operator widzi tylko własne/przypisane certyfikaty i powiązane osoby/płatników)
- Cały cykl życia odnowienia: skanowanie → priorytetyzowane zadania ToDo → zaproszenie e-mail z szablonu i załącznikami → przypomnienia → odnowienie albo porzucenie → statystyki zadań
- Raporty w trzech perspektywach z opisu pracy (użytkownik certyfikatu, płatnik, administrator), harmonogram wygaśnięć, globalna wyszukiwarka z powodem dopasowania, dziennik zdarzeń
- Wymiana danych: eksport CSV/XML, import CSV/XML z transakcyjnym podglądem każdego wiersza (podgląd wykonuje rzeczywiste zapisy i wycofuje je), import wiadomości e-mail (EML) dopasowywanych do rejestru
- Frontend bez CDN i bez internetu: Tailwind budowany lokalnie, Vue i czcionka Inter w repozytorium
- Szkielety treści zamiast pustych ekranów, cache agregatów pulpitu unieważniany przy każdym zapisie, nagłówki cache dla zasobów statycznych

## Uwaga dotycząca powiązanego projektu

Moduł prywatnych subskrypcji (Netflix, Spotify, siłownia itp.) **nie jest już częścią tej aplikacji** — od 2026-09-18 stanowi osobny, niezależny projekt „Menedżer Subskrypcji” z własną bazą danych, kontami i interfejsem. Oba projekty nie mają żadnych wspólnych tabel ani powiązań.
