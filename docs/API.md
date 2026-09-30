# API

Wszystkie endpointy leżą w `api/*.php` (cienkie pliki wywołujące `App\Http\ApiKernel::run()`), zwracają JSON, wymagają aktywnej sesji oraz — dla zapisów — nagłówka `X-CSRF-TOKEN`. Kontrola dostępu jest egzekwowana po stronie serwera zgodnie z hierarchią ról **ADMIN > MANAGER > OPERATOR** (`App\Rbac`); ukrywanie przycisków w interfejsie (Vue) to tylko wygoda, nie zabezpieczenie.

Błędy biznesowe mapowane są na kody HTTP przez `ApiKernel`: `400` (błędne dane), `403` (brak uprawnień), `404` (nie znaleziono / poza zakresem widoczności operatora), `409` (konflikt, np. duplikat numeru seryjnego), `422` (walidacja), `500` (błąd nieoczekiwany, bez szczegółów w odpowiedzi).

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
| `api/dashboard.php` | Zagregowane KPI pulpitu (cache'owane per rola/właściciel) |
| `api/attachments.php` | Biblioteka plików dla szablonów i zaproszeń |

## Uwierzytelnianie (poza `api/`)

Logowanie nie jest endpointem JSON pod `api/` — to osobne strony wejściowe, bo prowadzą przez przekierowania i wiadomości e-mail, nie przez wywołania AJAX:

| Strona | Rola |
|---|---|
| `login.php` | logowanie e-mailem i hasłem; limit prób (`App\Auth\LoginThrottle`) |
| `register.php` | rejestracja publiczna (jeśli włączona w `config/auth.php`) — konto nieaktywne do potwierdzenia adresu |
| `verify-email.php` | potwierdzenie adresu jednorazowym linkiem z wiadomości |
| `set-password.php` | ustawienie/zresetowanie hasła linkiem z wiadomości ("nie pamiętam hasła", konto założone przez administratora) |
| `logout.php` | wylogowanie |

Logika stoi w `App\AuthManager` (`register` → `verifyEmail` → `attemptLogin`, plus `requestPasswordSetLink` / `setPasswordWithToken`); hasła wyłącznie jako `password_hash()`, tokeny jako SHA-256, jednorazowe, z terminem ważności. Szczegóły: `CLAUDE.md` §„Authentication”.

## Kluczowe funkcje aplikacji

- Logowanie adresem e-mail i hasłem, adres potwierdzany jednorazowym linkiem z wiadomości wysyłanej przez SMTP (OAuth2 albo hasłem aplikacji); ochrona CSRF, limit prób logowania; konta dezaktywowane i przypisywane przez ADMIN-a (rejestracja publiczna jest przełącznikiem w `config/auth.php`)
- Uprawnienia oparte o role z zakresem danych operatora (operator widzi tylko własne/przypisane certyfikaty i powiązane osoby/płatników)
- Cały cykl życia odnowienia: skanowanie → priorytetyzowane zadania ToDo → zaproszenie e-mail z szablonu i załącznikami → przypomnienia → odnowienie albo porzucenie → statystyki zadań
- Raporty w trzech perspektywach z opisu pracy (użytkownik certyfikatu, płatnik, administrator), harmonogram wygaśnięć, globalna wyszukiwarka z powodem dopasowania, dziennik zdarzeń
- Wymiana danych: eksport CSV/XML, import CSV/XML z transakcyjnym podglądem każdego wiersza (podgląd wykonuje rzeczywiste zapisy i wycofuje je), import wiadomości e-mail (EML) dopasowywanych do rejestru
- Frontend bez CDN i bez internetu: Tailwind budowany lokalnie, Vue i czcionka Inter w repozytorium
- Szkielety treści zamiast pustych ekranów, cache agregatów pulpitu unieważniany przy każdym zapisie, nagłówki cache dla zasobów statycznych
