# Uruchomienie projektu (środowisko lokalne)

Instrukcja do pracy nad kodem na własnym komputerze (np. Laragon). Wdrożenie na darmowy hosting publiczny (link dla promotora) opisuje osobno [`DEPLOY_INFINITYFREE.md`](DEPLOY_INFINITYFREE.md).

## Wymagania wstępne

- PHP 8.1+ z rozszerzeniami PDO/MySQL
- MySQL 8 lub MariaDB 10.5+
- Composer
- Apache z `mod_rewrite` i `AllowOverride All` (np. środowisko Laragon) — projekt zakłada, że katalog aplikacji jest jednocześnie webroot
- Node.js — opcjonalnie, tylko do przebudowy arkusza stylów po zmianach w klasach Tailwind (zbudowany `assets/css/app.css` jest już w repozytorium)

## Instalacja zależności

```powershell
composer install
```

## Konfiguracja

- `config/database.php` — dane połączenia z MySQL (`host`, `dbname`, `username`, `password`, `charset`); domyślnie `localhost` / `assistent_subscriptions` / `root` bez hasła (typowe dla Laragon)
- `config/auth.php` — `self_registration` (włącz/wyłącz publiczną rejestrację) i `default_role` konta z rejestracji
- `config/intake.php` (domyślne) i `config/intake.local.php` (sekrety, poza gitem) — odbiór wniosków z e-maila: rejestr firm (Biała Lista MF, `registry`), token webhooka (`webhook.token`) i skrzynka IMAP (`imap`: `enabled`, `host`, `username`, `password` albo `auth => oauth2`); patrz komentarze w `config/intake.php`
- `config/mail.local.php` — konfiguracja poczty, **plik nieśledzony przez git** (sekrety); utworzyć na podstawie wzorca:

```powershell
copy config\mail.local.php.example config\mail.local.php
```

  - sterownik `sandbox` — wiadomości trafiają wyłącznie na [mailtrap.io](https://mailtrap.io) (bezpieczne do testów)
  - sterownik `oauth2` — rzeczywista wysyłka przez SMTP z autoryzacją OAuth2 (XOAUTH2), bez hasła do skrzynki — patrz komentarz w `config/mail.local.php.example` (Gmail/Microsoft 365) i `php scripts/oauth2-token.php`
  - sterownik `smtp` — rzeczywista wysyłka hasłem aplikacji (Gmail) albo Mailtrap Email Sending
  - sterownik `log` — wiadomości zapisywane do `logs/mail.log`, bez wysyłki (wygodne na intranecie bez serwera SMTP)

## Baza danych

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

## Dane demonstracyjne (opcjonalnie)

```powershell
php scripts\seed-demo-data.php
```

Wypełnia panel certyfikatami we wszystkich priorytetach, beneficjentami, płatnikami, archiwum z łańcuchem odnowień, zadaniami ToDo w każdym statusie, zaproszeniami z załącznikiem i historią zdarzeń. Konta demo (`@example.com`) mają od razu ustawione hasło i potwierdzony adres, więc można się nimi zalogować bez poczty. Ponowne uruchomienie z `--force` odtwarza dane od nowa; `php scripts\cleanup-demo-data.php` usuwa wszystkie dane biznesowe.

## Pierwsze konto administratora

Konto administratora zakłada się jednym z trzech sposobów:

1. **Rejestracja + ręczne podniesienie roli** — jeśli `config/auth.php` ma `self_registration => true`: zarejestruj się na `/register.php`, potwierdź adres linkiem z wiadomości, potem:
   ```powershell
   php scripts\set-role.php twoj@email.pl ADMIN
   ```
2. **Bez rejestracji, hasło od razu** (przydatne, gdy poczta jeszcze nie działa): wstaw konto SQL-em (patrz [`DEPLOY_INFINITYFREE.md`](DEPLOY_INFINITYFREE.md) krok 7) albo utwórz je zwykłym `INSERT` lokalnie, a potem:
   ```powershell
   php scripts\set-password.php --email=twoj@email.pl --password="Twoje haslo 2026"
   ```
   Ustawia hasło **i** potwierdza adres e-mail w jednym kroku.
3. **Link „ustaw hasło” na e-mail**:
   ```powershell
   php scripts\set-password.php --email=twoj@email.pl --send-link
   ```

Pełny opis wszystkich narzędzi wiersza poleceń: [`CLI.md`](CLI.md).

## Uruchomienie aplikacji

Po skopiowaniu katalogu do webroot serwera (np. `C:\laragon\www\assistent_subscription`) i uruchomieniu usług Apache + MySQL:

- Strona główna: `http://localhost/assistent_subscription/`
- Panel: `http://localhost/assistent_subscription/dashboard.php`

## Zadanie cykliczne (cron)

```powershell
php cron\renewals.php
```

Odbiór wniosków ze skrzynki IMAP (jeśli włączony w `config/intake.local.php`) — co kilka minut:

```powershell
php cron\mail-intake.php
```

Skaner odnowień (zakłada zadania ToDo) oraz wysyłka zaległych przypomnień o zaproszeniach — uruchamiać codziennie (np. Harmonogram zadań Windows: program = pełna ścieżka do `php.exe`, argument = pełna ścieżka do skryptu). Wynik zapisywany do `logs/renewals.log`. Na hostingu bez crontaba patrz `deploy-cron.php` w [`DEPLOY_INFINITYFREE.md`](DEPLOY_INFINITYFREE.md).

## Testy i statyczna analiza

```powershell
composer test                                    # PHPUnit — testy jednostkowe
$env:RUN_INTEGRATION_TESTS=1; composer test      # + testy integracyjne (tymczasowa baza assistent_subscriptions_test)
composer stan                                    # PHPStan (poziom 5)
composer cs                                      # PHP-CS-Fixer (tryb dry-run)
```

Konto MySQL z `config/database.php` musi mieć uprawnienia do tworzenia i usuwania bazy testowej.
