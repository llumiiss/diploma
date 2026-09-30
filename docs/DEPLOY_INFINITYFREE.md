# Wdrożenie na darmowy hosting (InfinityFree) — dla promotora

Cel: link, który promotor otwiera na dowolnym urządzeniu, bez uruchamiania czegokolwiek lokalnie. InfinityFree to darmowy, klasyczny hosting PHP + MySQL (Apache, bez Node/Dockera) — dokładnie ten sam stos co lokalnie w Laragon, więc kod nie wymaga żadnych zmian, tylko konfiguracji.

**Ograniczenia darmowego planu, o których trzeba wiedzieć:**
- Brak SSH — pliki wgrywa się przez FTP, migracje bazy uruchamia się przez przeglądarkę (`deploy-migrate.php` w tym repo).
- Brak prawdziwego crontaba — dzienny skaner odnowień (`cron/renewals.php`) trzeba zastąpić darmowym „webcronem” (`deploy-cron.php` + [cron-job.org](https://cron-job.org)).
- Wychodzący SMTP bywa ograniczany na darmowych planach. **Zweryfikuj to na samym początku (krok 6)** — od wyniku zależy, czy pokazujesz wysyłkę zaproszeń na żywo, czy tylko w podglądzie.
- Konto administratora zakładamy **bezpośrednio w bazie** (SQL), więc pierwsze logowanie promotora nie zależy w ogóle od działania poczty.

---

## 1. Konto i hosting

1. Załóż konto na [infinityfree.com](https://infinityfree.com) (bez karty płatniczej).
2. „Create Account” → wybierz darmową subdomenę, np. `certisub.infinityfreeapp.com` (albo podepnij własną domenę, jeśli masz).
3. Poczekaj aż konto hostingowe przejdzie w stan aktywny (zwykle kilka minut), potem wejdź w „Control Panel” tego konta (nie mylić z „Client Area”, które jest do zarządzania samym kontem InfinityFree).

## 2. Baza danych MySQL

1. W panelu: **MySQL Databases** → utwórz nową bazę (np. `certisub`) — InfinityFree doda prefiks konta, np. `if0_XXXXXXX_certisub`.
2. Zanotuj: **nazwę bazy**, **nazwę użytkownika** (zwykle taka sama jak baza), **hasło** (ustawiasz sam) i **hostname MySQL** pokazany w panelu (coś w stylu `sqlXXX.infinityfree.com` — **to nie jest `localhost`**, w przeciwieństwie do Laragon).
3. Panel zwykle udostępnia też link do **phpMyAdmin** dla tej bazy — będzie potrzebny w kroku 7.

## 3. Poczta testowa (Mailtrap Sandbox)

1. Załóż darmowe konto na [mailtrap.io](https://mailtrap.io) → **Email Testing** → domyślny inbox.
2. W ustawieniach inboxa (SMTP Settings) skopiuj **Username** i **Password** dla `sandbox.smtp.mailtrap.io`, port **2525**.
3. Wiadomości wysłane z aplikacji (np. zaproszenia do odnowienia) trafią tylko do tego inboxa, nigdy na prawdziwe adresy — bezpieczne do pokazu.

## 4. Konfiguracja plików do wgrania

Wykonaj to **lokalnie**, w osobnej kopii projektu (nie w katalogu roboczym), żeby nie ruszać commitowanego kodu ani niedokończonej pracy:

```powershell
git archive HEAD | tar -x -C sciezka\do\pustego\folderu\certisub-deploy
cd sciezka\do\pustego\folderu\certisub-deploy
composer install --no-dev --optimize-autoloader
```

W tej kopii (**nie** w repozytorium git!) edytuj:

**`config/database.php`** — dane z kroku 2:
```php
return [
    'host'     => 'sqlXXX.infinityfree.com',
    'dbname'   => 'if0_XXXXXXX_certisub',
    'username' => 'if0_XXXXXXX',
    'password' => 'twoje-haslo-do-bazy',
    'charset'  => 'utf8mb4',
];
```

**`config/mail.local.php`** (skopiuj z `config/mail.local.php.example`, potem edytuj):
```php
return [
    'driver' => 'sandbox',
    'dev_log_codes' => false,
    'smtp' => [
        'username' => 'twoj_mailtrap_username',
        'password' => 'twoj_mailtrap_password',
    ],
];
```

**`config/auth.php`** — dla publicznego linku warto wyłączyć otwartą rejestrację (żeby przypadkowi goście nie zakładali sobie kont):
```php
return [
    'self_registration' => false,
    'default_role'      => 'OPERATOR',
];
```

**`config/deploy.local.php`** (skopiuj z `config/deploy.local.php.example`) — wygeneruj dwa losowe tokeny:
```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```
i wpisz je jako `migrate_token` / `cron_token`. **Zapisz je sobie** — będą potrzebne w krokach 6 i 8.

## 5. Wgranie plików (FTP)

1. W panelu InfinityFree: **FTP Accounts** — zanotuj hostname (zwykle `ftpupload.net`), login i hasło (ustawiasz sam).
2. Połącz się [FileZillą](https://filezilla-project.org/) (albo dowolnym klientem FTP).
3. Wejdź do katalogu **`htdocs`** — to jest webroot. Wgraj tam **całą zawartość** przygotowanej kopii z kroku 4 (łącznie z `vendor/`), **oprócz**: `.git/`, `.claude/`, `node_modules/`, `tests/` (opcjonalnie — oszczędza tylko miejsce, nie przeszkadza jeśli zostanie).
4. Upewnij się, że `storage/attachments/`, `storage/cache/` i `logs/` istnieją i są zapisywalne (InfinityFree zwykle daje pełne prawa zapisu we własnym katalogu — nie trzeba nic dodatkowo ustawiać).

## 6. Test wysyłki e-mail (zanim pójdziesz dalej)

Otwórz w przeglądarce: `https://twoja-domena/scripts/test-mail.php` — **nie zadziała**, bo `scripts/` jest zablokowane przez `.htaccess` (CLI-only, celowo). Zamiast tego najprościej zweryfikować pocztę już po zalogowaniu, wysyłając testowe zaproszenie z panelu (krok 9), albo tymczasowo dodać drobny test — jeśli wysyłka się nie powiedzie, sprawdzisz to po prostu próbując wysłać zaproszenie i patrząc na `logs/mail-errors.log` przez FTP.

Jeżeli SMTP okaże się zablokowany na darmowym planie: **nie blokuje to reszty demo** — logowanie promotora (krok 7) nie zależy od poczty. Ograniczysz się do pokazania ekranu wysyłki zaproszeń bez faktycznej dostawy, albo przełączysz `driver` na `log` i pokażesz treść wiadomości z `logs/mail.log`.

## 7. Migracja bazy i pierwsze konto administratora

1. Uruchom **raz**: `https://twoja-domena/deploy-migrate.php?token=TWÓJ_MIGRATE_TOKEN&fresh=1`
   Powinieneś zobaczyć „Zaimportowano świeży schemat…” i listę zastosowanych migracji.
2. Wygeneruj hash hasła dla konta administratora:
   ```powershell
   php -r "echo password_hash('TwojeHaslo123!', PASSWORD_DEFAULT), PHP_EOL;"
   ```
3. W **phpMyAdmin** (link z kroku 2) otwórz bazę i wykonaj SQL (wstaw swój e-mail i hash z kroku 2):
   ```sql
   INSERT INTO users (first_name, last_name, role, email, password_hash, email_verified_at)
   VALUES ('Imię', 'Nazwisko', 'ADMIN', 'twoj@email.pl', '<HASH_Z_KROKU_2>', NOW());
   ```
   Konto jest od razu aktywne i z potwierdzonym adresem — logowanie nie wymaga żadnej wiadomości e-mail.
4. Zaloguj się: `https://twoja-domena/login.php` → e-mail + hasło ustawione w kroku 7.2.

## 8. Cron (webcron)

1. Załóż darmowe konto na [cron-job.org](https://cron-job.org).
2. Nowe zadanie: URL = `https://twoja-domena/deploy-cron.php?token=TWÓJ_CRON_TOKEN`, harmonogram: codziennie (np. 7:00).
3. Pierwsze uruchomienie możesz też wywołać ręcznie, wchodząc na ten URL w przeglądarce — odpowiedź pokaże, ile zadań/przypomnień utworzył.

## 9. Ostatnie porządki

- Po udanej migracji **usuń `deploy-migrate.php` z serwera przez FTP** (albo przynajmniej zmień token w `config/deploy.local.php` na nowy losowy) — endpoint z opcją `&fresh=1` kasuje całą bazę, nie powinien zostać dostępny z aktywnym starym tokenem.
- `deploy-cron.php` może zostać (potrzebny cron-job.org), token go chroni.
- Sprawdź, że `config/database.php`, `config/mail.local.php`, `config/deploy.local.php` z **prawdziwymi danymi** zostały tylko na hostingu — **nigdy nie commituj ich z powrotem do repozytorium** (to publiczne repo na GitHubie).

## 10. Link dla promotora

`https://twoja-domena/` — strona główna, stamtąd `login.php`. Warto dopisać link do [README.md](../README.md) po udanym wdrożeniu.
