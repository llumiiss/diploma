# CertiSub Assistant

Praca inżynierska (Uniwersytet Śląski) — webowa aplikacja LAMP do ewidencji **certyfikatów** (m.in. kwalifikowanych podpisów i pieczęci, certyfikatów SSL, code signing, domen, usług SaaS) wraz z ich **użytkownikami (beneficjentami)** i **płatnikami**, obejmująca cały proces odnowienia: skanowanie terminów, zadania ToDo, zaproszenia e-mail z szablonami i załącznikami, przypomnienia, archiwizację, historię zdarzeń, raporty oraz wymianę danych (CSV/XML/EML).

## Dokumentacja

| Dokument | Zawartość |
|---|---|
| [`docs/URUCHOMIENIE.md`](docs/URUCHOMIENIE.md) | Instrukcja uruchomienia krok po kroku: wymagania, konfiguracja, baza danych, pierwsze konto, testy |
| [`docs/API.md`](docs/API.md) | Opis endpointów API i kluczowych funkcji aplikacji |
| [`docs/CLI.md`](docs/CLI.md) | Skrypty wiersza poleceń (`scripts/`, `cron/`) i ich odpowiedniki HTTP na hostingu bez SSH |
| [`docs/DEPLOY_INFINITYFREE.md`](docs/DEPLOY_INFINITYFREE.md) | Wdrożenie na darmowy publiczny hosting (wersja demonstracyjna) |
| [`docs/MAPA_PROJEKTU.md`](docs/MAPA_PROJEKTU.md) | Oficjalny opis pracy, macierz wymagań, model danych, plan etapów, dziennik zmian |
| [`docs/INSTRUKCJA_OBSLUGI.md`](docs/INSTRUKCJA_OBSLUGI.md) | Instrukcja obsługi i scenariusz demonstracyjny |

## Cel projektu i tło akademickie

Zgodnie z oficjalnym opisem pracy dyplomowej, celem systemu jest wspieranie administratora w nadzorze nad certyfikatami wymagającymi cyklicznego odnowienia. Aplikacja wiąże ze sobą trzy typy informacji — **certyfikat** (dane usługi: numer seryjny, daty ważności i wygaśnięcia, wymagany margines odnowienia), **użytkownika certyfikatu** (dane osobowe beneficjenta) i **płatnika** (podmiot rozliczający usługę) — i na tej podstawie udostępnia raportowanie w trzech perspektywach:

- **Użytkownik certyfikatu** — daty odnowienia, szczegóły certyfikatu, historia zdarzeń.
- **Płatnik** — lista certyfikatów, terminy wygaśnięcia, powiązane osoby, historia.
- **Administrator** — zarządzanie całym systemem niezależnie od perspektyw.

System w sposób cykliczny skanuje bazę danych, wyszukuje certyfikaty zbliżające się do terminu odnowienia w założonym marginesie czasowym, tworzy priorytetyzowaną listę zadań „ToDo”, wysyła zaproszenia do odnowienia e-mailem (szablon + załączniki) i zarządza przypomnieniami — a każdy krok tego procesu zapisywany jest w historii zdarzeń. Dostęp do systemu jest oparty o hierarchię kont z podziałem na role (ADMIN > MANAGER > OPERATOR), a dane podlegają archiwizacji zamiast trwałego usuwania. Dodatkowo aplikacja umożliwia eksport danych do CSV/XML oraz import z plików CSV/XML/EML.

Choć opis pracy wskazywał wstępnie Javę ze Spring jako narzędzie realizacji, po analizie dostępnych opcji (studium wykonalności) do budowy warstwy webowej wybrano **PHP** w architekturze **LAMP**, zgodnie z częścią opisu dopuszczającą tę technologię dla jądra aplikacji bazodanowej.

## Wersja demonstracyjna (online)

Aplikacja działa też jako publiczny link, bez uruchamiania czegokolwiek lokalnie — wystarczy przeglądarka na dowolnym urządzeniu. Instrukcja wdrożenia na darmowy hosting PHP+MySQL: [`docs/DEPLOY_INFINITYFREE.md`](docs/DEPLOY_INFINITYFREE.md).

**Demo:** https://subscriptionassistent.freedev.app/ (hosting w trakcie aktywacji po stronie InfinityFree — zob. [status platformy](https://status.infinityfree.com/); link zacznie działać automatycznie, kod i baza są już wdrożone)

## Stack technologiczny

- **Backend:** PHP 8.1+ (rozwijane na 8.3), architektura bez frameworka — własna warstwa usług (`App\Service`) i kontroler HTTP (`App\Http\ApiKernel`), PDO z natywnymi zapytaniami przygotowanymi (prepared statements)
- **Baza danych:** MySQL 8 / MariaDB 10.5+ (silnik InnoDB, `utf8mb4`)
- **Frontend:** Vue 3 bez kroku budowania (ładowany bezpośrednio, bez CDN — biblioteka wendorowana w repozytorium), Tailwind CSS 3 budowany lokalnie (`npm run css`), czcionka Inter dołączona do repozytorium — aplikacja działa również bez dostępu do internetu (intranet)
- **Poczta:** PHPMailer — SMTP z autoryzacją OAuth2 (Gmail/Microsoft 365), SMTP hasłem (Mailtrap/Gmail) albo sterownik `log`/`sandbox` na potrzeby demo
- **Serwer WWW:** Apache z `mod_rewrite` i `AllowOverride All` (np. Laragon) — `.htaccess` blokuje katalogi wewnętrzne
- **Jakość i testy:** PHPUnit 11 (testy jednostkowe + integracyjne na osobnej bazie), PHPStan (poziom 5), PHP-CS-Fixer (@PSR12)
- **i18n:** PL (domyślny), EN, ES, DE, UK

Instrukcja uruchomienia krok po kroku (wymagania, konfiguracja, zmienne, komendy): [`docs/URUCHOMIENIE.md`](docs/URUCHOMIENIE.md).

## Struktura katalogów i kluczowe moduły

```
assistent_subscription/
├── index.php, login.php, register.php, logout.php   # wejście, logowanie (e-mail + hasło), rejestracja, wylogowanie
├── verify-email.php, set-password.php               # potwierdzenie adresu i ustawienie hasła linkiem z wiadomości
├── deploy-migrate.php, deploy-cron.php               # odpowiedniki migracji i crona przez HTTP (hosting bez SSH)
├── dashboard.php                                     # punkt wejścia panelu (Vue)
├── api/                     # cienkie endpointy JSON — logika w classes/Api
├── classes/
│   ├── Service/             # reguły biznesowe: walidacja, uprawnienia (Rbac), zakres danych operatora, historia zdarzeń
│   ├── Http/                # ApiKernel (CSRF, sesja, mapowanie wyjątków na kody HTTP), Request, Response
│   ├── Api/                 # kontrolery obsługujące poszczególne endpointy
│   ├── Auth/                # PasswordPolicy, VerificationTokens, LoginThrottle
│   ├── Mail/                # OAuth2TokenProvider (wysyłka XOAUTH2)
│   ├── Exchange/             # formaty wymiany danych: czytniki/zapisy CSV i XML, parser EML, aliasy kolumn
│   └── Migrations/           # idempotentne migracje schematu bazy
├── includes/                # powłoka panelu, wspólny <head>, przełącznik języka
├── assets/                  # css (build Tailwind), js (komponenty Vue bez kroku budowania), fonty, vendor
├── cron/                    # renewals.php — skaner odnowień + przypomnienia o zaproszeniach
├── database/schema.sql      # pełny schemat do świeżej instalacji
├── scripts/                 # migrate, seed-demo-data, cleanup-demo-data, set-role, set-password, oauth2-token, mailtrap-inbox, test-mail
├── storage/                 # attachments/ (załączniki) i cache/ (agregaty pulpitu) — niedostępne przez HTTP
├── docs/                    # dokumentacja (patrz tabela wyżej) i materiały pracy dyplomowej
└── tests/                   # PHPUnit — testy jednostkowe i integracyjne
```

**Model danych:** `certificates`, `beneficiaries` (użytkownicy certyfikatów), `payers`, `users` (konta personelu), `renewal_tasks` (zadania ToDo), `email_templates` + `attachments` + `email_template_attachments`, `invitations` + `invitation_attachments`, `events` (historia/oś czasu), `settings` (progi odnowienia), `email_verifications` (jednorazowe tokeny z wiadomości e-mail), `login_attempts` (limit prób logowania), `login_otps` (historia logowań sprzed Etapu 9). Szczegóły: `docs/MAPA_PROJEKTU.md` §2.2.

Opis endpointów API i kluczowych funkcji aplikacji: [`docs/API.md`](docs/API.md).

## Uwaga dotycząca powiązanego projektu

Moduł prywatnych subskrypcji (Netflix, Spotify, siłownia itp.) **nie jest już częścią tej aplikacji** — od 2026-09-18 stanowi osobny, niezależny projekt „Menedżer Subskrypcji” z własną bazą danych, kontami i interfejsem. Oba projekty nie mają żadnych wspólnych tabel ani powiązań.
