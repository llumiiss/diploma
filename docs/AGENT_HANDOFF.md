# CertiSub Assistant — podsumowanie projektu + roadmapa dla agentów

> **Dokument historyczny.** Roadmapa z tego pliku jest nieaktualna względem opisu pracy — obowiązuje [MAPA_PROJEKTU.md](MAPA_PROJEKTU.md) (§6 porównuje oba dokumenty).

**Projekt:** Informatyczny asystent zarządzania certyfikatami i subskrypcjami (CertiSub Assistant)  
**Autor:** Maksym Litosh  
**Uczelnia:** Uniwersytet Śląski w Katowicach  
**Kierunek:** Informatyka stacjonarna I stopnia  
**Promotor:** Jarosław Utracki  
**Środowisko:** Laragon (Windows), PHP 8.x, MySQL, Apache  
**URL lokalne:** http://localhost/assistent_subscription/  
**Ostatnia aktualizacja handoff:** 2026-06 (sesja z użytkownikiem)

---

## 1. Czym jest projekt

Aplikacja webowa MVP do centralnego śledzenia:

1. **Asystent firmowy** (`dashboard.php`) — certyfikaty SSL, SaaS, domeny, wsparcie chmurowe, podpis kodu.
2. **Asystent prywatny** (`dashboard-personal.php`) — streaming, muzyka, gry, fitness, chmura, aplikacje (np. Cursor AI).

Jedna platforma, dwa scope’y (`corporate` / `personal`), wspólny layout dashboardu, różne typy i progi alertów.

**Cel pracy dyplomowej:** zaprojektować i zaimplementować działający prototyp + część pisemną.

---

## 2. Stos technologiczny

| Warstwa | Technologia |
|---------|-------------|
| Backend | PHP ≥ 8.1, PDO, klasy w `App\` |
| Frontend dashboard | Vue.js 3 (CDN), Tailwind CSS (CDN), Inter |
| Baza | MySQL / MariaDB — DB `assistent_subscriptions` |
| Mail | PHPMailer 6.9 (SMTP / Mailtrap sandbox) |
| Testy | PHPUnit 11 |
| Pakiety | Composer |
| Dev | Laragon (Apache + PHP + MySQL) |

**Architektura:** hybrydowa — PHP SSR osadza JSON w HTML, Vue przejmuje interakcje; API JSON przez `fetch()`.

---

## 3. Struktura katalogów (istotne pliki)

```
assistent_subscription/
├── index.php                 # Landing page
├── login.php, register.php   # OTP auth
├── logout.php
├── dashboard.php             # Panel firmowy (scope=corporate)
├── dashboard-personal.php    # Panel prywatny (scope=personal)
├── bootstrap.php             # Autoload, sesja, Translator
├── api/
│   ├── add_subscription.php  # POST: dodaj do manager_subskrypcji
│   └── delete_account.php    # POST: usuń konto
├── classes/                  # Logika biznesowa (patrz niżej)
├── config/
│   ├── database.php
│   ├── mail.php
│   ├── mail.local.php        # lokalny override (gitignore)
│   └── mail.local.php.example
├── cron/send_reminders.php   # CLI: e-mail 3 dni przed płatnością
├── database/
│   ├── schema.sql            # pełna świeża instalacja
│   ├── migration_login_otp.sql
│   └── migration_manager_subskrypcji.sql
├── includes/
│   ├── dashboard_app.php     # cały UI Vue dashboardu
│   ├── head.php              # Tailwind + style
│   └── lang_switcher.php
├── lang/                     # pl, en, es, de, uk
├── scripts/
│   ├── migrate.php
│   ├── test-mail.php
│   └── cleanup-demo-data.php
├── tests/                    # PHPUnit
├── docs/
│   ├── thesis_part1.md       # CZĘŚCIOWO NIEAKTUALNY (mówi że brak auth)
│   ├── AGENT_HANDOFF.md      # TEN PLIK
│   └── generate_thesis_pdfs.py
└── composer.json
```

---

## 4. Klasy PHP (`classes/`)

| Klasa | Rola |
|-------|------|
| `Database` | Singleton PDO |
| `AuthManager` | OTP: request/verify, rejestracja, sesja, delete account, rate limits |
| `UserManager` | CRUD użytkowników (częściowy), deleteAccountCompletely |
| `SubscriptionManager` | Odczyt subskrypcji + KPI per scope |
| `SubscriptionHelper` | Priorytety, progi, etykiety, enrichment |
| `ManagerSubscriptionManager` | Osobiste subskrypcje użytkownika (create + get) |
| `PayerManager` | Lista płatników (read-only) |
| `Translator` | i18n |
| `Csrf` | Tokeny CSRF |
| `Session` | Start/destroy sesji, cookie path |
| `Mailer` / `EmailService` / `MailConfig` | Wysyłka e-mail |
| `OtpMailer` | Interfejs mailera OTP |
| `MigrationRunner` | Idempotentne migracje |

---

## 5. Baza danych

### Tabele
- `users` — first_name, last_name, role (ADMIN/MANAGER/OPERATOR), email UNIQUE
- `login_otps` — code_hash, expires_at, used_at, attempt_count
- `subscriptions` — scope (corporate/personal), type ENUM, expiry, user_id, payer_id, status, cost, billing_cycle, payment_status…
- `payers` — company_name, contact_person, tax_id
- `manager_subskrypcji` — nazwa_uslugi, mail_subskrypcji, username_konta, koszt_pln, data_nastepnej_platnosci, user_id
- migracje śledzone przez `MigrationRunner` (tabela migracji)

### Typy subskrypcji
- **Corporate:** SSL_CERTIFICATE, SAAS, DOMAIN, CLOUD_SUPPORT, CODE_SIGNING (+ OTHER)
- **Personal:** STREAMING, MUSIC, GAMING, FITNESS, CLOUD_STORAGE, SAAS

### Progi alertów (`SubscriptionHelper`)
| Scope | Warning | Critical |
|-------|---------|----------|
| corporate | 30 dni | 7 dni |
| personal | 15 dni | 3 dni |

---

## 6. Co JEST zaimplementowane (stan aktualny)

### Frontend / UX
- [x] Landing page z CTA firmowe/prywatne
- [x] Logowanie OTP (2 kroki: e-mail → kod)
- [x] Rejestracja + weryfikacja OTP
- [x] Dashboard firmowy i prywatny (wspólny `dashboard_app.php`)
- [x] Nawigacja: Pulpit, To-Do, Subskrypcje, Użytkownicy, Płatnicy
- [x] Karty KPI, statusy, płatności wymagające działania, priorytetowe odnowienia
- [x] Oś czasu odnowienia (po kliknięciu wiersza)
- [x] Wyszukiwanie + filtry (typ, płatność, priorytet) po stronie Vue
- [x] Modal dodawania subskrypcji do menedżera osobistego
- [x] Usuwanie konta (modal + API)
- [x] i18n: PL, EN, ES, DE, UK
- [x] Przełącznik asystenta firmowy ↔ prywatny

### Backend / bezpieczeństwo
- [x] Auth passwordless OTP (TTL 10 min, max 5 prób, limit wysyłek)
- [x] Hash kodów OTP, brak ujawniania istnienia konta
- [x] CSRF na formularzach i API (`X-CSRF-TOKEN`)
- [x] PDO prepared statements
- [x] Whitelist redirectów po logowaniu
- [x] Cron tylko z CLI

### API
- [x] `POST api/add_subscription.php` — dodaj do `manager_subskrypcji`
- [x] `POST api/delete_account.php` — usuń konto

### Mail / cron
- [x] PHPMailer + Mailtrap/SMTP
- [x] Cron: przypomnienie 3 dni przed płatnością **tylko** dla `manager_subskrypcji`
- [x] Logi: `logs/otp.log`, `logs/reminders.log`

### DevOps / jakość
- [x] `scripts/migrate.php`, `schema.sql`
- [x] PHPUnit: AuthManager, MailConfig, MigrationRunner
- [x] `scripts/test-mail.php`, `cleanup-demo-data.php`

### Dokumentacja dyplomowa (wygenerowana wcześniej)
- [x] PDF: `założenia i daty Litosh.pdf` (Downloads + docs)
- [x] PDF: `Szkic pracy Litosh.pdf`
- [x] Harmonogram bi-weekly (06.2026–12.2026) uzgodniony z użytkownikiem (wersja uproszczona)

---

## 7. Czego NIE MA / limity MVP (ważne dla agentów)

| Brak | Szczegóły |
|------|-----------|
| CRUD `subscriptions` | Dashboard firmowy/prywatny pokazuje dane głównie **read-only**; brak API create/update/delete dla tabeli `subscriptions` |
| Edit/Delete menedżera | Tylko **create** w `ManagerSubscriptionManager` + `add_subscription.php` |
| CRUD users/payers w UI | Widoki listy istnieją; brak formularzy i endpointów zapisu |
| RBAC | Role w DB istnieją, **nie ograniczają** widoków ani API |
| Cron dla `subscriptions` | Przypomnienia tylko dla `manager_subskrypcji` |
| Import/export | Brak CSV/Excel |
| Integracje zewnętrzne | Brak API SSL/WHOIS/dostawców |
| Wykresy | Brak Chart.js itp. |
| `docs/thesis_part1.md` | **Nieaktualny** — twierdzi że auth jest poza MVP (już jest) |

---

## 8. Konwencje kodu (przestrzegaj)

- PHP: `declare(strict_types=1);`, klasy `final`, namespace `App\`
- API: JSON, CSRF header, auth wymagane, walidacja wejścia
- Dashboard: jeden plik `includes/dashboard_app.php` dla obu scope’ów — konfiguracja przez `$scope`
- i18n: klucze w `lang/*.php`, nie hardcoduj PL w UI
- Nie commituj `config/mail.local.php` / sekretów
- Nie rozszerzaj scope poza to, o co prosi użytkownik (zasada użytkownika: focused changes)

---

## 9. ROADMAPA — co planowaliśmy dalej (kolejność)

### PRIORYTET 1 — dokończenie funkcjonalne MVP (program)

1. **CRUD subskrypcji firmowych/prywatnych (`subscriptions`)**
   - Backend: create / update / delete w `SubscriptionManager`
   - API: np. `api/subscriptions.php` lub osobne endpointy
   - Frontend: formularze w dashboardzie (modal jak przy menedżerze)
   - Walidacja: daty, ENUM typów, scope, user_id, payer_id

2. **Edycja i usuwanie w `manager_subskrypcji`**
   - Metody w `ManagerSubscriptionManager`
   - Endpointy API + przyciski w tabeli „Moje Subskrypcje”

3. **CRUD użytkowników i płatników**
   - Formularze + API
   - Widoki `users` / `payers` już są — podpiąć zapis

4. **RBAC**
   - ADMIN / MANAGER / OPERATOR realnie ograniczają akcje
   - Middleware/helper w AuthManager lub osobna klasa
   - Ukrywanie przycisków w Vue wg roli

5. **Rozszerzenie cron**
   - Przypomnienia też dla tabeli `subscriptions`
   - (opcjonalnie) szablony i18n dla e-maili

6. **QoL UX**
   - Loading states, komunikaty sukcesu/błędu, empty states
   - Responsywność dashboardu

### PRIORYTET 2 — jakość i zamknięcie programu

7. Testy PHPUnit dla SubscriptionManager + API
8. Scenariusze E2E manualne (rejestracja → CRUD → cron)
9. Feature freeze + cleanup demo data
10. Aktualizacja `docs/thesis_part1.md` i README pod aktualny stan

### PRIORYTET 3 — część pisemna pracy (równolegle / potem)

11. Rozdziały 1–3: wstęp, rynek, założenia  
12. Rozdziały 4–5: baza, implementacja  
13. Rozdziały 6–7: powiadomienia, testy  
14. Rozdział 8 + bibliografia + załączniki  
15. Prezentacja obronna + demo

### Harmonogram bi-weekly (wersja uzgodniona z użytkownikiem — prosta)

| Okres | Cel |
|-------|-----|
| 16–30.06.2026 | Stabilizacja MVP, konsultacja wstępna |
| 01–15.07.2026 | API CRUD `subscriptions` |
| 16–31.07.2026 | Frontend CRUD subskrypcji |
| 01–15.08.2026 | CRUD payers/users + edit/delete menedżera |
| 16–31.08.2026 | RBAC + bezpieczeństwo |
| 01–15.09.2026 | Cron + QoL UI |
| 16–30.09.2026 | Testy + konsultacja promotora |
| 01–15.10.2026 | Finalizacja programu (freeze) |
| 16.10–31.12.2026 | Część pisemna + obrona |

---

## 10. Szybki start dla nowego agenta

```powershell
cd C:\laragon\www\assistent_subscription
# Laragon: Start All (Apache + MySQL)
# DB: import schema.sql LUB php scripts/migrate.php
composer install
composer test
php scripts\test-mail.php email@example.com
```

URLe:
- Landing: http://localhost/assistent_subscription/
- Firmowy: …/dashboard.php
- Prywatny: …/dashboard-personal.php

Konto testowe użytkownika (przykład z sesji): logowanie OTP na e-mail używany w rejestracji (np. mobi.litosh@gmail.com). Kody OTP w Mailtrap albo `logs/otp.log` na localhost.

---

## 11. Dokumenty PDF / praca dyplomowa

Wygenerowane skryptem `docs/generate_thesis_pdfs.py`:
- `założenia i daty Litosh.pdf`
- `Szkic pracy Litosh.pdf`

Kopiowane też do `C:\Users\Lumis\Downloads\`.

Uwaga: `docs/thesis_part1.md` wymaga aktualizacji przed użyciem w pracy (auth i panel personal już istnieją).

---

## 12. Co zrobić NAJPIERW przy wznowieniu prac (rekomendacja)

Jeśli użytkownik powie „kontynuuj projekt / dokończ MVP”, zacznij od:

1. **CRUD `subscriptions`** (backend + API + modal w `dashboard_app.php`) — największa luka funkcjonalna.
2. Potem **edit/delete** w menedżerze osobistym.
3. Potem RBAC.
4. Nie ruszaj części pisemnej, dopóki użytkownik o to nie poprosi — chyba że wyraźnie każe.

Przy każdej zmianie: trzymaj styl istniejących klas, CSRF + auth na API, i18n kluczy, testy jeśli dodajesz logikę auth/CRUD.
