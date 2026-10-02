# Skrypty wiersza poleceń (CLI)

Wszystkie skrypty w `scripts/` i `cron/` mają na początku strażnika `PHP_SAPI !== 'cli'` i są dodatkowo zablokowane od strony HTTP przez `.htaccess` — uruchamia się je wyłącznie z terminala (`php scripts\nazwa.php` / `php cron\nazwa.php`). Na hostingu bez dostępu do terminala (np. darmowy plan bez SSH) zastępują je dwa chronione tokenem pliki HTTP opisane w [`DEPLOY_INFINITYFREE.md`](DEPLOY_INFINITYFREE.md): `deploy-migrate.php` i `deploy-cron.php`.

## Baza danych i konta

| Skrypt | Do czego służy |
|---|---|
| `scripts/migrate.php` [`--fresh`] | Uruchamia zaległe migracje; z `--fresh` **usuwa wszystkie tabele**, importuje `database/schema.sql` od nowa i dopiero potem migruje — tylko na nowej/tymczasowej bazie |
| `scripts/seed-demo-data.php` [`--force`] [`--owner=e-mail`] [`--password=hasło`] | Wypełnia bazę pełnym zestawem danych demonstracyjnych (certyfikaty, beneficjenci, płatnicy, zadania, zaproszenia, historia); konta demo (`@example.com`) mają od razu hasło i potwierdzony adres |
| `scripts/cleanup-demo-data.php` | Usuwa dane biznesowe i konta `@example.com`; zostawia prawdziwe konta i szablony wiadomości |
| `scripts/set-role.php <e-mail> <ADMIN\|DIRECTOR\|MANAGER\|ACCOUNTANT\|IT\|OPERATOR\|EMPLOYEE>` / `--list` | Zmienia rolę istniejącego konta; `--list` pokazuje wszystkie konta z rolami |
| `scripts/set-password.php --email=... --password='...'` / `--send-link` / `--list` | Narzędzie awaryjne: ustawia hasło i potwierdza adres w jednym kroku (bez wysyłki poczty), albo wysyła link „ustaw hasło”; `--list` pokazuje stan kont (hasło/adres potwierdzony/ostatnie logowanie) |

## Poczta

| Skrypt | Do czego służy |
|---|---|
| `scripts/test-mail.php <adres>` | Wysyła wiadomość testową bieżącym sterownikiem z `config/mail.local.php` — sprawdza, czy konfiguracja SMTP/OAuth2 działa |
| `scripts/oauth2-token.php` [`--client-id=...`] [`--client-secret=...`] [`--provider=microsoft --tenant=...`] | Jednorazowe: zdobywa `refresh_token` do wysyłki OAuth2 (Gmail/Microsoft 365) — otwiera adres zgody, odbiera kod przez lokalny nasłuch na `127.0.0.1:8765`, wypisuje token do wklejenia w `config/mail.local.php` |
| `scripts/mailtrap-inbox.php` [`--link`] [`--link=adres`] [`--show=id`] | Podgląd skrzynki Mailtrap Sandbox z terminala (wymaga bloku `mailtrap` w `config/mail.local.php`) — wygodne przy sterowniku `sandbox`, żeby nie klikać w przeglądarce na mailtrap.io |

## Odbiór wniosków z poczty

| Skrypt | Do czego służy |
|---|---|
| `scripts/mail-pipe.php` | Czyta wiadomość ze standardowego wejścia i zamienia ją na wniosek (alias serwera pocztowego: `wnioski: "\|php /sciezka/scripts/mail-pipe.php"`, albo ręcznie `php scripts/mail-pipe.php < wniosek.eml`); kody wyjścia: 0 przyjęto/pominięto/duplikat, 65 wiadomość nieczytelna, 75 błąd przejściowy |

## Zadanie cykliczne

| Skrypt | Do czego służy |
|---|---|
| `cron/mail-intake.php` | Odbiór wniosków o certyfikat ze skrzynki IMAP (co kilka minut): pobiera nieprzeczytane wiadomości, zamienia je na wnioski do sprawdzenia i oznacza jako przeczytane albo przenosi do skrzynki „przetworzone”; konfiguracja w `config/intake.local.php`; wynik w `logs/mail-intake.log`, awaria połączenia → powiadomienie administratorów w aplikacji |
| `cron/renewals.php` | Skaner odnowień (zakłada zadania ToDo w marginesie odnowienia, podnosi priorytety) i wysyłka zaległych przypomnień o zaproszeniach; wynik w `logs/renewals.log`; uruchamiać raz dziennie (Harmonogram zadań Windows albo crontab) |

## Odpowiedniki HTTP (hosting bez SSH/crontaba)

| Plik | Odpowiednik | Ochrona |
|---|---|---|
| `deploy-migrate.php?token=...[&fresh=1]` | `scripts/migrate.php [--fresh]` | token z `config/deploy.local.php` |
| `deploy-cron.php?token=...` | `cron/renewals.php` | token z `config/deploy.local.php` |

Pełna instrukcja użycia obu — patrz [`DEPLOY_INFINITYFREE.md`](DEPLOY_INFINITYFREE.md).
