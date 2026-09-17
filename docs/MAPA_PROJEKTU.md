# Mapa projektu — CertiSub Assistant (praca inżynierska)

> Mapa robocza dla agentów i autora. Utworzona 2026-09-16 na podstawie: oficjalnego opisu pracy (Załącznik A), `CLAUDE.md`, `docs/AGENT_HANDOFF.md`, `docs/thesis_part1.md`, treści obu PDF-ów (źródło: `docs/generate_thesis_pdfs.py`), przeglądu całego kodu, bazy danych i wyników testów.
>
> **Hierarchia źródeł:** 1) oficjalny opis pracy → 2) ustalenia z promotorem → 3) ta mapa → 4) `CLAUDE.md` / `docs/AGENT_HANDOFF.md` (roadmapa nieaktualna względem 1) → 5) `docs/thesis_part1.md` (nieaktualny).

Legenda: ✅ zrobione · 🟡 częściowo · ❌ brak · ⚠️ błąd lub ryzyko

---

## 0. Najważniejsze wnioski

1. **Kod realizuje inny produkt niż opis pracy.** Opis: system ewidencji certyfikatów (np. kwalifikowanych) z użytkownikami certyfikatów, płatnikami, listą zadań, zaproszeniami e-mail, importem/eksportem i raportami. Kod: tracker subskrypcji firmowych (SSL, SaaS, domeny) i prywatnych (Netflix, Spotify).
2. **Pokrycie 19 wymagań funkcjonalnych po Etapie 1: 0 kompletnych, 14 częściowych, 5 brakujących** (na starcie 0 / 8 / 11; macierz w §3). Większość częściowych to „model danych gotowy, brak ekranów i logiki”.
3. **Rdzeń wciąż nie działa od początku do końca:** w aplikacji nadal nie da się dodać certyfikatu ani płatnika (Etap 2). Dane do demonstracji wprowadza `scripts/seed-demo-data.php`.
4. **`CLAUDE.md` jest spójny z kodem, ale nie z opisem pracy.** Roadmapa pomija m.in. import/eksport, szablony i załączniki, rejestr zaproszeń, archiwizację, zadania ze statusami i statystykami, historię na osi czasu oraz raporty perspektyw (§6).
5. **Harmonogram jest przesunięty:** CRUD (lipiec), RBAC (sierpień), cron + UX (1–15.09) nie są w kodzie. Freeze 15.10.2026 jest nierealny przy pełnym zakresie (§7).
6. **Błędy krytyczne z §5.1 zostały naprawione w Etapie 0 (2026-09-16)** — dziennik zmian w §11. Były to: skrypt z `scripts/` kasujący dane bez logowania, publiczny dostęp do `.git/` i logów, niedziałające wylogowanie, brak kontroli dostępu do danych oraz kasowanie rekordów biznesowych przy usuwaniu konta.
7. **Mocne strony do ponownego użycia (§4):** logowanie OTP z testami, CSRF, migracje, mailer, layout panelu, narzędzia jakości.
8. **Dodatkowe wymagania autora (§2.5):** skeleton loadery i cache'owanie — zaplanowane jako Etap 7.
9. **Decyzje D1–D5 i D7 podjęte 2026-09-16 (§8):** panel prywatny zamrożony, perspektywy jako raporty dla personelu, rejestracja tylko przez ADMIN, nowe teksty PL+EN z domyślnym PL, `subscriptions` → `certificates`, ogólny model typów certyfikatów. Otwarty zostaje D6 (harmonogram).
10. **Etap 1 (model danych) wykonany 2026-09-16 (§11):** certyfikaty z typami wg D7, użytkownicy certyfikatów, rozszerzeni płatnicy, archiwizacja, zadania ToDo ze statusami, szablony, załączniki, zaproszenia i historia zdarzeń. Struktura po migracji jest identyczna ze świeżą instalacją. Brakuje ekranów i logiki (Etapy 2–7).

---

## 1. Mapa systemu — stan dziś vs cel

```
CertiSub Assistant (docelowo)
├── Dostęp
│   ├── ✅ Logowanie OTP (e-mail), sesje, CSRF, whitelist przekierowań
│   ├── ✅ Wylogowanie (naprawione w Etapie 0)
│   ├── ✅ Pliki wewnętrzne i skrypty CLI zablokowane przez HTTP (Etap 0)
│   ├── 🟡 Hierarchia ról — klasa Rbac + filtrowanie danych wg roli (Etap 0);
│   │       zostaje egzekwowanie uprawnień do akcji i API (Etap 2)
│   └── ⚠️ Publiczna rejestracja — do wyłączenia, konta zakłada ADMIN (D3, Etap 2)
├── Ewidencja  (model danych ✅ Etap 1 — ekrany w Etapie 2)
│   ├── 🟡 Certyfikaty / usługi — nr seryjny, wystawca, ważność, wymagany czas odnowienia; w UI tylko odczyt
│   ├── 🟡 Użytkownicy certyfikatów (beneficjenci) — osobna tabela powiązana z płatnikiem; brak ekranu
│   ├── 🟡 Płatnicy — dane kontaktowe i adres; w UI tylko odczyt
│   └── 🟡 Archiwizacja — archived_at, widoki pomijają zarchiwizowane; brak ekranu archiwum
├── Proces odnowień  (model danych ✅ Etap 1 — logika w Etapie 3)
│   ├── 🟡 Skaner (cron) — działa tylko dla prywatnego menedżera, „dokładnie za 3 dni”
│   ├── 🟡 Lista ToDo — tabela zadań ze statusami gotowa; w panelu nadal filtr listy
│   ├── 🟡 Szablony wiadomości + załączniki — 4 szablony PL/EN, tabela załączników; brak ekranu
│   ├── 🟡 Zaproszenia + przypomnienia — rejestr wysyłek w bazie; brak wysyłki
│   └── 🟡 Statystyki zadań — dane do grupowania wg statusów; brak widoku
├── Przegląd i raporty
│   ├── 🟡 Pulpit KPI (statusy, płatności, priorytety)
│   ├── ❌ Karta użytkownika certyfikatu (perspektywa Użytkownika)
│   ├── ❌ Karta płatnika (perspektywa Płatnika)
│   ├── ❌ Panel administratora
│   ├── 🟡 Wyszukiwarka — tylko lista subskrypcji, po stronie przeglądarki
│   └── 🟡 Oś czasu — tabela events z kontekstem certyfikat/użytkownik/płatnik; w panelu nadal statyczny pasek
├── Wymiana danych
│   ├── ❌ Eksport CSV / XML
│   └── ❌ Import CSV / XML / EML
└── Dodatki spoza opisu pracy
    ├── 🧊 Panel prywatny (Netflix, Spotify…) + menedżer „Moje subskrypcje” — zamrożony (D1)
    ├── ✅ i18n: PL, EN, ES, DE, UK
    └── ✅ Strona główna (landing)
```

---

## 2. Wizja docelowa

### 2.1. Główny przepływ (cykl życia odnowienia)

```
[Ewidencja]      certyfikat + użytkownik certyfikatu + płatnik
                 (formularz lub import CSV / XML / EML)
     │
     ▼
[Skaner]         cron codziennie: certyfikaty w marginesie odnowienia
     │
     ▼
[ToDo]           zadanie z priorytetem wg zakresu dat, status „do zrobienia”
     │
     ▼
[Zaproszenie]    e-mail z szablonu + załączniki → beneficjent / płatnik
     │
     ▼
[Przypomnienia]  kolejne wysyłki wg reguł, rejestr każdej wysyłki
     │
     ▼
[Wynik]          odnowiono (nowy certyfikat, stary → archiwum) | porzucono
     │
     ▼
[Przegląd]       historia na osi czasu · statystyki zadań · raporty perspektyw · eksport
```

Każdy krok zapisuje zdarzenie w historii (`events`) — z niej powstaje oś czasu i ścieżka realizacji.

### 2.2. Model danych (docelowy)

| Encja (tabela) | Najważniejsze pola | Stan po Etapie 1 |
|---|---|---|
| Konta personelu (`users`) | imię, nazwisko, e-mail, rola | ✅ opiekunowie rekordów (`certificates.user_id`) i przydział zadań |
| Użytkownicy certyfikatów (`beneficiaries`) | imię, nazwisko, e-mail, telefon, `payer_id`, notatki, `archived_at` | ✅ |
| Płatnicy (`payers`) | nazwa, osoba kontaktowa, NIP (unikalny), e-mail, telefon, adres, kod pocztowy, miasto, `archived_at` | ✅ |
| Certyfikaty (`certificates`, dawniej `subscriptions`) | `certificate_type` (D7), numer seryjny (unikalny u wystawcy), wystawca, ważny od / do, `renewal_lead_days`, `user_id`, `beneficiary_id`, `payer_id`, `previous_certificate_id`, koszt i płatność, `archived_at` | ✅ |
| Zadania ToDo (`renewal_tasks`) | `certificate_id`, przypisane konto, status (todo / in_progress / done / abandoned), priorytet, termin, wynik, data zamknięcia; co najwyżej jedno otwarte zadanie na certyfikat | ✅ |
| Szablony (`email_templates`) | kod, język, nazwa, temat, treść HTML i tekstowa z polami `{imie}`, `{nazwisko}`, `{typ_certyfikatu}`, `{numer_seryjny}`, `{data_waznosci}`, `{dni_do_wygasniecia}`, `{platnik}` | ✅ 4 domyślne (zaproszenie i przypomnienie, PL i EN) |
| Załączniki (`attachments` + `email_template_attachments`) | nazwa oryginalna, nazwa pliku w `storage/attachments`, typ MIME, rozmiar, SHA-256 | ✅ |
| Zaproszenia (`invitations` + `invitation_attachments`) | certyfikat, zadanie, szablon, odbiorca (użytkownik certyfikatu lub płatnik), treść z chwili wysyłki, status (queued / sent / failed / responded / closed), przypomnienia, błąd | ✅ |
| Historia (`events`) | typ i id encji, typ zdarzenia, konto, kontekst (certyfikat, użytkownik, płatnik), dane JSON, czas — celowo bez kluczy obcych | ✅ |
| `login_otps`, `schema_migrations` | — | ✅ |
| `manager_subskrypcji` | — | 🧊 moduł dodatkowy, zamrożony (D1) |

Tabele są gotowe w bazie; ekrany i logika dla nich powstają w Etapach 2–5.

Relacje: płatnik 1–N użytkownik certyfikatu · użytkownik 1–N certyfikat · płatnik 1–N certyfikat · certyfikat 1–N zadanie · zadanie 1–N zaproszenie · szablon N–N załącznik · wszystkie encje → `events`.

`payer_id` zostaje także przy certyfikacie: domyślnie przepisywany z płatnika beneficjenta, ale konkretny certyfikat może opłacać inny podmiot (np. po zmianie pracodawcy).

### 2.3. Role i perspektywy (zatwierdzone 2026-09-16 — D2, D3)

| Rola | Zakres |
|---|---|
| ADMIN | pełny dostęp: konta i role, szablony i załączniki, progi odnowień, import, archiwum, historia zdarzeń |
| MANAGER | wszystko, co OPERATOR, + przydzielanie zadań, statystyki, raporty, eksport, archiwizacja |
| OPERATOR | ewidencja certyfikatów, osób i płatników; zadania ToDo; zaproszenia i przypomnienia |

Perspektywy z opisu jako raporty w panelu:

- **Użytkownik** — karta osoby: certyfikaty, daty odnowienia, szczegóły, historia.
- **Płatnik** — karta płatnika: certyfikaty, daty wygaśnięcia, historia, powiązane osoby.
- **Administrator** — zarządzanie systemem niezależnie od perspektyw.

Opcjonalnie (kierunek rozwoju): logowanie OTP dla użytkowników certyfikatów i płatników z dostępem tylko do własnej karty.

### 2.4. Ekrany docelowe

1. Logowanie OTP (bez publicznej rejestracji)
2. Pulpit: KPI, priorytety, statystyki zadań wg statusów
3. ToDo: zadania z priorytetami, zmiana statusu, przydział, „wyślij zaproszenie”
4. Certyfikaty: lista, filtry, dodaj/edytuj, archiwizuj, szczegóły z osią czasu
5. Użytkownicy certyfikatów: lista, dodaj/edytuj, karta (perspektywa Użytkownika)
6. Płatnicy: lista, dodaj/edytuj, karta (perspektywa Płatnika)
7. Zaproszenia: rejestr wysyłek, statusy, przypomnienia, ponowna wysyłka
8. Szablony i załączniki (ADMIN)
9. Import / eksport (podgląd i walidacja przed zapisem)
10. Wyszukiwarka globalna z powiązaniami
11. Archiwum
12. Administracja: konta, role, progi odnowień, historia zdarzeń

### 2.5. Wymagania dodatkowe autora (poza opisem pracy)

**Skeleton loadery** — placeholdery zamiast pustego ekranu i skoków układu:

- Dziś panel jest renderowany serwerowo, a `[v-cloak] { display: none; }` (`includes/head.php:37`) ukrywa cały interfejs do momentu zamontowania Vue — widać pusty ekran. Pierwszy krok jest możliwy od razu: skeleton obecny w HTML, ukrywany po montażu Vue (odwrotność `v-cloak`).
- Kolejne kroki po Etapie 2, gdy listy będą ładowane przez `fetch`: skeleton wierszy tabel (certyfikaty, zadania, zaproszenia), skeleton kart KPI, skeleton kart beneficjenta i płatnika.
- Realizacja bez bibliotek: wspólny zestaw klas CSS + animacja shimmer, wyłączana przy `prefers-reduced-motion`. Skeleton musi odwzorowywać docelowy układ (liczba kolumn, wysokość wiersza), żeby po załadowaniu treść nie przeskakiwała.

**Cache'owanie** — trzy warstwy w kolejności wdrożenia:

1. **Agregaty KPI i statystyki** (`getStatusStats`, `getPaymentSummary`, `getRenewalSummary`, statystyki zadań) — klasa `Cache` z TTL (plik w `storage/cache` albo tabela), unieważnianie przy każdym zapisie certyfikatu lub zadania. Sens dopiero przy realnych danych — dziś w bazie jest 0 rekordów, więc to zadanie po Etapie 2–3.
2. **Zasoby statyczne** — po przeniesieniu Tailwind i Vue z CDN lokalnie (N3): `Cache-Control: public, max-age=31536000, immutable` dla plików z wersją w nazwie, `ETag`/`Last-Modified` dla stron.
3. **Drobny cache aplikacyjny** — słowniki i18n i konfiguracja (pliki PHP obsługuje już opcache), raporty per beneficjent i płatnik na czas sesji.

⚠️ Zasada bezpieczeństwa: klucz cache'a musi zawierać rolę i właściciela rekordów. Wspólny cache agregatów bez tego klucza pokazałby OPERATOROWI dane całej organizacji, czyli cofnąłby naprawę z Etapu 0.

---

## 3. Macierz wymagań (opis pracy → stan → dowód → co zrobić)

### 3.1. Funkcjonalne

| ID | Wymaganie z opisu | Stan | Dowód w kodzie | Do zrobienia |
|---|---|---|---|---|
| F1 | Dane certyfikatu: nr seryjny, ważność, wygaśnięcie, wymagania odnowienia | 🟡 | model gotowy: `certificates` z `serial_number`, `issuer`, `valid_from`, `expiry_date`, `renewal_lead_days` (Etap 1) | formularze i API zapisu (Etap 2) |
| F2 | Użytkownicy certyfikatu (dane osobowe beneficjentów) | 🟡 | tabela `beneficiaries` oddzielona od kont logowania (Etap 1) | ekran ewidencji (Etap 2) |
| F3 | Płatnicy (dane płatnika) | 🟡 | dane kontaktowe i adres w modelu (Etap 1); w UI tylko odczyt (`classes/PayerManager.php`) | CRUD (Etap 2) |
| F4 | Beneficjent powiązany z płatnikiem | 🟡 | klucze obce `beneficiaries.payer_id`, `certificates.beneficiary_id`, `certificates.payer_id` (Etap 1) | widoki powiązań (Etapy 2 i 4) |
| F5 | Dodawanie, edycja, archiwizacja | 🟡 | `archived_at` w certyfikatach, użytkownikach certyfikatów i płatnikach; widoki pomijają zarchiwizowane (Etap 1); zapis z UI tylko w menedżerze osobistym | API CRUD, akcja „archiwizuj”, widok archiwum (Etap 2) |
| F6 | Perspektywa Użytkownika: daty odnowienia, szczegóły, historia | ❌ | brak widoku osoby i historii | karta użytkownika certyfikatu |
| F7 | Perspektywa Płatnika: certyfikaty, wygaśnięcia, historia, osoby | ❌ | widok płatników = nazwa, kontakt, liczba, suma (`includes/dashboard_app.php:469-492`) | karta płatnika |
| F8 | Perspektywa Administratora | ❌ | brak panelu | panel administracyjny |
| F9 | Eksport CSV / XML | ❌ | — | eksport list i kart (`fputcsv`, `XMLWriter`) |
| F10 | Import CSV / XML / EML | ❌ | — | import z podglądem; EML przez parser MIME w czystym PHP (np. `zbateson/mail-mime-parser`) |
| F11 | Regularne skanowanie i margines odnowienia | 🟡 | cron tylko dla `manager_subskrypcji`, warunek „dokładnie za 3 dni” (`cron/send_reminders.php`); progi 7/30 dni liczone przy wyświetlaniu (`classes/CertificateHelper.php`); wymagany czas odnowienia per certyfikat w modelu (`renewal_lead_days`) | skaner certyfikatów tworzący zadania (Etap 3) |
| F12 | Lista ToDo z priorytetami wg zakresów dat | 🟡 | tabela `renewal_tasks` ze statusami i priorytetem (Etap 1); w panelu ToDo to nadal filtr listy | tworzenie zadań przez skaner i ekran zadań (Etap 3) |
| F13 | Zaproszenia e-mail z szablonem i załącznikami | 🟡 | tabele `email_templates` (4 domyślne szablony), `attachments`, `invitations` (Etap 1); `Mailer::send` wciąż bez załączników (`classes/Mailer.php`) | wysyłka z szablonu + `addAttachment` (Etap 3) |
| F14 | Zarządzanie wysłanymi zaproszeniami (przypomnienia) | 🟡 | rejestr `invitations`: status, liczba i termin przypomnień, błąd wysyłki (Etap 1) | reguły przypomnień i ekran rejestru (Etap 3) |
| F15 | Dostęp oparty o konta użytkowników | 🟡 | OTP, sesje, CSRF, wylogowanie i testy działają (`classes/AuthManager.php`, `tests/Unit/AuthManagerTest.php`, Etap 0); publiczna rejestracja wciąż otwarta | wyłączyć rejestrację, konta zakłada ADMIN (D3, Etap 2) |
| F16 | Wyszukiwanie usług, osób, płatników i powiązań | 🟡 | filtr w przeglądarce tylko po liście subskrypcji (`includes/dashboard_app.php:669-678`) | wyszukiwarka serwerowa po wszystkich encjach |
| F17 | Ścieżka realizacji i powiązania na osi czasu | 🟡 | tabela `events` z kontekstem certyfikat / użytkownik / płatnik (Etap 1); w panelu nadal statyczny pasek dat | zapis zdarzeń przy każdej akcji (Etapy 2–3), widok osi czasu (Etap 4) |
| F18 | Statystyki zadań wg statusów | 🟡 | dane w `renewal_tasks.status`: do zrobienia / w toku / zrobione / porzucone (Etap 1) | agregaty i wykres na pulpicie (Etap 3) |
| F19 | Hierarchiczny plan kont z rolami | 🟡 | klasa `Rbac` (ADMIN > MANAGER > OPERATOR), filtrowanie rekordów po właścicielu i ukryty katalog osób/płatników (Etap 0, `classes/Rbac.php`) | egzekwowanie uprawnień do akcji i endpointów API, zarządzanie kontami i rolami w panelu (Etap 2) |

### 3.2. Niefunkcjonalne i techniczne

| ID | Wymaganie | Stan | Uwagi |
|---|---|---|---|
| N1 | PHP + HTML + JavaScript | ✅ | PHP 8.3, Vue 3, Tailwind |
| N2 | Baza zgodna z MySQL (LAMP), standardowy hosting | ✅ | MySQL 8.4, PDO, prepared statements |
| N3 | Instalacja publiczna lub intranetowa | 🟡 ⚠️ | katalog projektu = katalog publiczny serwera, brak reguł blokujących (§5 pkt 1–2); frontend z CDN — Tailwind Play CDN (`includes/head.php:9`), Vue z unpkg bez przypiętej wersji (`includes/dashboard_app.php:576`), Google Fonts (`includes/head.php:33-35`), więc w sieci bez internetu interfejs nie działa; `display_errors=On` w php.ini Laragona |
| N4 | Studium wykonalności (punkt wyjścia: Java + Spring) | 🟡 | tylko jedno zdanie o Spring Boot (`docs/thesis_part1.md:74`) — potrzebne porównanie z kryteriami |
| N5 | Ochrona danych osobowych | 🟡 | dobrze: PDO, CSRF, hash OTP, regeneracja sesji; źle: §5.1, brak historii zmian, limit OTP w sesji |
| N6 | Testy | 🟡 | 28 testów: 26 jednostkowych (logowanie, role, tłumaczenia, logika certyfikatów, poczta) + 2 integracyjne (migracje i model Etapu 1); wciąż brak testów API i crona |

---

## 4. Co już jest i nadaje się do ponownego użycia

| Element | Plik | Jak wykorzystać |
|---|---|---|
| Logowanie OTP (TTL 10 min, 5 prób, hash kodów, bez ujawniania istnienia kont) + testy | `classes/AuthManager.php` | bez zmian; dodać zakładanie kont przez ADMIN |
| CSRF dla formularzy i API | `classes/Csrf.php` | wszystkie nowe endpointy |
| Wzorzec endpointu JSON (metoda → CSRF → auth → walidacja) | `api/add_subscription.php` | szablon nowych API |
| Idempotentne migracje | `classes/MigrationRunner.php`, `classes/Migrations/` | każda zmiana schematu jako nowa migracja; `SchemaInspector` sprawdza stan przed każdym krokiem |
| Progi i priorytety | `classes/CertificateHelper.php` | priorytety zadań ToDo |
| Wysyłka e-mail (PHPMailer) | `classes/Mailer.php` | dodać załączniki i szablony |
| Szkielet crona z logowaniem | `cron/send_reminders.php` | przebudować na skaner certyfikatów |
| Layout panelu, karty KPI, tabele, modale | `includes/dashboard_app.php` | nowe widoki |
| i18n z fallbackiem do EN | `classes/Translator.php` | nowe klucze |
| PHPUnit, PHPStan (poziom 5), PHP-CS-Fixer | `composer.json` | bramka jakości |
| Dane demonstracyjne | `scripts/seed-demo-data.php` | wypełnia bazę pod demo, testy ręczne i zrzuty ekranu do pracy; `--force` odtwarza od zera |
| Instrukcja obsługi | `docs/INSTRUKCJA_OBSLUGI.md` | opis paneli, scenariusz demonstracji, rozwiązywanie problemów; baza rozdziału P5 |

---

## 5. Co wymaga poprawek w istniejącym kodzie

Numeracja ciągła — odwołania w innych miejscach: „§5 pkt N”.

### 5.1. ✅ Krytyczne — naprawione w Etapie 0 (2026-09-16)

> Wszystkie pięć pozycji jest naprawionych i zweryfikowanych. Opisy zostawiam jako historię i uzasadnienie zmian; co dokładnie zrobiono — §11.

1. **Kasowanie danych bez logowania.** Katalog projektu jest katalogiem publicznym serwera, a jedyny `.htaccess` leży w `cron/`. `scripts/cleanup-demo-data.php` nie sprawdza `PHP_SAPI` i wykonuje `DELETE FROM subscriptions` oraz `DELETE FROM payers` (`scripts/cleanup-demo-data.php:21-22`) po zwykłym wejściu na adres. `scripts/migrate.php` przez WWW uruchomi zaległe migracje (bez `--fresh`, bo `register_argc_argv=Off`). *Wniosek z kodu i konfiguracji — skryptu celowo nie uruchamiano.*
   → blokada `PHP_SAPI !== 'cli'` w każdym skrypcie + katalog `public/` jako katalog główny serwera albo `Require all denied` dla `scripts/`, `classes/`, `config/`, `database/`, `logs/`, `tests/`, `docs/`, `vendor/`, `.git/`.
2. **Publiczne pliki wewnętrzne** (sprawdzone przez HTTP, kod 200): `.git/config` i `.git/HEAD` (da się odtworzyć całe repozytorium z historią), `logs/reminders.log` (cron zapisuje tam adresy e-mail odbiorców; przy włączonym `dev_log_codes` także `logs/otp.log` z kodami OTP), `database/schema.sql`, `composer.lock`, `docs/`. `config/mail.local.php` nigdy nie trafił do gita, więc hasła pocztowe tą drogą nie wyciekają.
3. **Wylogowanie nie działa.** `logout.php:12` wywołuje `$auth->logout()`, ale `$auth` nie istnieje. Efekt: fatal error (zapisany w `C:\laragon\tmp\php_errors.log`), sesja nie jest niszczona. PHPStan tego nie wykrył, bo `phpstan.neon` nie obejmuje plików z katalogu głównego.
4. **Każdy może się zarejestrować i zobaczyć wszystkie dane.** `register.php` jest publiczny, a `includes/dashboard_app.php:69-74` ładuje wszystkie subskrypcje, osoby (z e-mailami) i płatników bez sprawdzania roli.
5. **Usunięcie konta kasuje rekordy biznesowe.** `UserManager::deleteAccountCompletely()` usuwa wszystkie subskrypcje osoby (`classes/UserManager.php:123`). To sprzeczne z wymaganiem archiwizacji i z opisanym w pracy `ON DELETE RESTRICT`.

### 5.2. Średnie

6. **Cron gubi przypomnienia:** warunek `=` dokładnie 3 dni (`cron/send_reminders.php:48`), więc jeden pominięty dzień oznacza brak wysyłki. Nie ma rejestru wysyłek, więc ponowne uruchomienie tego samego dnia wyśle duplikaty. Treść jest wpisana po polsku w kodzie, wbrew konwencji i18n.
7. **`cron/.htaccess`** ma niepełną dyrektywę `Require all` (bez `denied`). Apache zwraca 500 zamiast 403, czyli blokuje dostęp przypadkiem. Poprawnie: `Require all denied`.
8. **Listy osób i płatników ukrywają rekordy bez subskrypcji** (`HAVING subscription_count > 0` w `classes/UserManager.php:34` i `classes/PayerManager.php:34`). Nowo dodany płatnik byłby niewidoczny, co blokuje CRUD.
9. **Limit wysyłek kodów OTP trzymany w sesji** (`classes/AuthManager.php:344-378`) — wystarczy usunąć ciasteczko, żeby go obejść. Przenieść do bazy (limit per e-mail i IP).
10. **Walidacja daty tylko wyrażeniem regularnym** (`api/add_subscription.php:56`) — np. `2026-02-31` przejdzie walidację i skończy się nieobsłużonym wyjątkiem bazy.
11. **Zależności z CDN** (N3) — Tailwind Play CDN nie jest przeznaczony na produkcję, a `vue@3` bez przypiętej wersji może zmienić się bez ostrzeżenia.

### 5.3. Drobne i porządki

12. Model danych: `annual_cost` przechowuje koszt miesięczny, gdy `billing_cycle = monthly` (`CertificateManager::getRenewalSummary`) — nadal do poprawy. Rozdzielenie kont i użytkowników certyfikatów zrobione w Etapie 1; `manager_subskrypcji` z polskimi nazwami kolumn zostaje jako zamrożony moduł dodatkowy (D1).
13. Oś czasu: „Następna płatność” bez daty (`includes/dashboard_app.php:356`). Strona główna pokazuje zmyślone liczby (`index.php:107-116`).
14. Domyślny język interfejsu to EN (`classes/Translator.php:58`), choć projekt i praca są po polsku.
15. JSON w `<script>` bez `JSON_HEX_TAG` dla users, payers, i18n i config (`includes/dashboard_app.php:99-106`). Dziś bezpieczne, bo `json_encode` escapuje `/`, ale warto ujednolicić z resztą.
16. `composer-setup.php` (instalator Composera) jest w repozytorium — usunąć. Katalog `.claude/` nie jest śledzony.
17. Nieaktualna dokumentacja: `docs/thesis_part1.md` (uwierzytelnianie „poza MVP”, e-mail jako placeholder — `docs/thesis_part1.md:40`; 3 tabele), a README i AGENT_HANDOFF opisują zakres z panelem prywatnym.
18. PHPStan: 1 znana uwaga (`cron/send_reminders.php:120`) — nie blokuje. Uwaga z `MigrationRunner.php:92` zniknęła w Etapie 1, bo metody sprawdzające schemat są poprawnie oznaczone jako nieczyste (`@phpstan-impure`).
19. PHP-CS-Fixer zgłasza 27 z 32 plików „do poprawy”, bo `@PSR12` wymaga końców linii LF, a pliki w repozytorium mają CRLF (Windows). Dotyczy to także plików, których nikt nie zmieniał, więc `composer cs-fix` przepisałby cały projekt. Do zrobienia osobno: `.gitattributes` z `* text eol=lf` i jednorazowa normalizacja w dedykowanym commicie.
21. Klasa `AddSubscriptionApiTest` w pliku `tests/Integration/MigrationRunnerTest.php` nigdy się nie uruchamia — PHPUnit wykrywa tylko klasę o nazwie pliku. Samo przeniesienie do osobnego pliku nie wystarczy: testowany `api/add_subscription.php` kończy się `exit`, co przerwałoby cały proces PHPUnit. Rozwiązanie to wydzielenie logiki endpointów do klas — naturalnie przy API CRUD w Etapie 2.

### 5.4. ✅ Wykryte i naprawione po Etapie 0 (2026-09-16)

20. **Różne strefy czasowe PHP i MySQL.** PHP działał w `UTC` (php.ini Laragona), a MySQL w strefie systemowej — o 01:36 czasu lokalnego PHP widział jeszcze poprzedni dzień. Skutek: `days_left` i priorytety liczone w PHP (`classes/SubscriptionHelper.php:36-62`) rozjeżdżały się o jeden dzień z licznikami KPI i cronem opartymi na `CURDATE()`, więc przypomnienie „za 3 dni” nigdy nie trafiało w swoją datę. Wykryte przy przygotowaniu danych demonstracyjnych. Naprawa: `bootstrap.php` ustawia `Europe/Warsaw`, a `scripts/seed-demo-data.php` liczy wszystkie daty od `CURDATE()` z bazy.

---

## 6. Porównanie celów: `CLAUDE.md` / `AGENT_HANDOFF.md` vs opis pracy

| Aspekt | Opis pracy | `CLAUDE.md` / `AGENT_HANDOFF.md` | Zgodność |
|---|---|---|---|
| Dziedzina | certyfikaty (np. kwalifikowane), użytkownicy certyfikatów, płatnicy | SSL, SaaS, domeny, płatności + panel prywatny (Netflix, Spotify) | ⚠️ poszerzone poza temat, a rdzeń zawężony |
| Kto wprowadza dane | zarządzający (personel) | każdy zarejestrowany użytkownik, dla siebie | ❌ |
| Konta i role | hierarchiczny plan kont z rolami | samorejestracja; RBAC dopiero na 4. miejscu roadmapy | 🟡 |
| Dodawanie, edycja, archiwizacja | wymagane | CRUD w roadmapie, archiwizacji brak | 🟡 |
| Import / eksport CSV, XML, EML | wymagane | wymienione tylko jako brak, **nie ma w roadmapie** | ❌ |
| ToDo z priorytetami | wymagane | widok-filtr; trwałych zadań brak w roadmapie | 🟡 |
| Zaproszenia z szablonem i załącznikami + zarządzanie nimi | wymagane | „rozszerzenie crona”, szablony „opcjonalnie”; załączników i rejestru brak | ❌ |
| Oś czasu ścieżki realizacji | wymagane | statyczna, brak w roadmapie | ❌ |
| Statystyki zadań wg statusów | wymagane | brak | ❌ |
| Raporty perspektyw (Użytkownik, Płatnik, Administrator) | wymagane | brak | ❌ |
| Wyszukiwanie z powiązaniami | wymagane | filtr w przeglądarce, brak w roadmapie | 🟡 |
| Technologia | PHP + HTML + JS, MySQL | PHP 8 + Vue + Tailwind, MySQL | ✅ |
| Studium wykonalności (Java/Spring) | wymagane w pracy | brak (dotyczy części pisemnej) | 🟡 |
| Część pisemna: dokumentacja użytkownika | wymagana | brak w spisie treści szkicu | ❌ |

**Wniosek:** roadmapa z `CLAUDE.md` (5 punktów) obejmuje ok. 5 z 19 wymagań funkcjonalnych (F1, F3, F5 bez archiwizacji, F11, F19). Jej punkt 2 (edycja i usuwanie w prywatnym menedżerze) jest poza opisem pracy. Konwencje kodu z `CLAUDE.md` (strict types, `final`, PDO, CSRF, i18n, testy) są dobre i zostają. Do zmiany jest sekcja „What this is” i roadmapa — po decyzjach z §8.

---

## 7. Plan dojścia do celu

Stan na 16.09.2026. Pierwotnie: freeze 15.10.2026, część pisemna 16.10–31.12.2026. Czas to szacunek w dniach roboczych.

| Etap | Zakres | Dni |
|---|---|---|
| 0. Naprawy krytyczne ✅ **wykonane 2026-09-16** | wszystko z §5.1; `cron/.htaccess`; konto ADMIN; PHPStan także dla plików z katalogu głównego (szczegóły w §11) | — |
| 1. Model danych ✅ **wykonane 2026-09-16** | migracje: `subscriptions` → `certificates` z typami wg D7, beneficjenci, rozszerzenie płatników, `archived_at`, `renewal_tasks`, `email_templates`, `attachments`, `invitations`, `events`; język domyślny PL (D4); panel prywatny nietknięty (D1); dane demonstracyjne (szczegóły w §11) | — |
| 2. Ewidencja + archiwizacja + RBAC | API i formularze dla certyfikatów, beneficjentów, płatników; archiwum; role w API i UI; wyłączenie publicznej rejestracji i zakładanie kont przez ADMIN (D3); zapis zdarzeń; etykiety „certyfikaty” w panelu i klucze i18n (przeniesione z D5) | 5–6 |
| 3. Proces odnowień | skaner → zadania ToDo (priorytety, statusy, przydział); szablony + załączniki; zaproszenia; rejestr i przypomnienia; statystyki zadań | 5–6 |
| 4. Raporty i przegląd | karta beneficjenta, karta płatnika, oś czasu z `events`, wyszukiwarka globalna | 3–4 |
| 5. Wymiana danych | eksport CSV/XML; import CSV/XML z podglądem; import EML | 3–4 |
| 6. Jakość i domknięcie | testy nowych serwisów, scenariusze E2E, lokalne zasoby frontendu, aktualizacja README i `CLAUDE.md`, freeze | 3–4 |
| 7. UX i wydajność (§2.5) | skeleton loadery w panelu; cache agregatów KPI z unieważnianiem przy zapisie i kluczem roli; nagłówki cache dla lokalnych zasobów z Etapu 6 | 2–3 |

**Pozostało ok. 21–27 dni roboczych** (Etapy 2–7, po odjęciu wykonanych Etapów 0 i 1), więc realny freeze wypada w połowie listopada 2026 (do uzgodnienia z promotorem). Rozdziały 1–3 pracy (wstęp, charakterystyka problemu, analiza rozwiązań, studium wykonalności) można pisać od razu — nie zależą od kodu.

Kolejność etapów = kolejność ważności. Gdy zabraknie czasu, najpierw upraszczać etap 5 (np. import EML ograniczony do jednego formatu wiadomości).

---

## 8. Decyzje (podjęte 2026-09-16) i ich konsekwencje

| ID | Decyzja | Wybór | Co z tego wynika |
|---|---|---|---|
| D1 | Panel prywatny (`dashboard-personal.php`, `manager_subskrypcji`, typy STREAMING, MUSIC…) | **b) zamrożony jako dodatek** — nie rozwijamy | kod zostaje bez zmian; kolumna `scope` zostaje; nowe funkcje (zadania, zaproszenia, import/eksport, raporty, archiwum) budujemy wyłącznie dla części certyfikatowej; w pracy opisać jako moduł dodatkowy i kierunek rozwoju |
| D2 | Perspektywy Użytkownik / Płatnik | **a) raporty w panelu dla personelu** | karta beneficjenta i karta płatnika jako widoki (Etap 4); osobne logowanie dla beneficjentów i płatników → rozdział „kierunki rozwoju” |
| D3 | Rejestracja | **a) wyłączona, konta zakłada ADMIN** | `register.php` przestaje być publiczny; ADMIN tworzy konto (imię, nazwisko, e-mail, rola), użytkownik loguje się kodem OTP; potrzebny ekran „Konta” w panelu administracyjnym (Etap 2) |
| D4 | i18n dla nowych funkcji | **b) PL + EN** | nowe klucze tylko w `lang/pl.php` i `lang/en.php` (ES/DE/UK dziedziczą EN dzięki fallbackowi w `Translator`); ✅ język domyślny PL (`Translator::DEFAULT_LOCALE`, Etap 1) |
| D5 | Nazwa głównej encji | **a) `subscriptions` → `certificates`** | ✅ Etap 1: tabela, kolumna `certificate_type`, klasy `CertificateManager` i `CertificateHelper`. Zmianę kluczy i18n przeniesiono do Etapu 2 — etykiety panelu zmienią się razem z nowym UI |
| D6 | Harmonogram | **otwarte — do ustalenia z promotorem** | plan §7 zakłada freeze w połowie listopada 2026 |
| D7 | Rodzaje certyfikatów | **b) ogólny model z typem** | ✅ Etap 1: `certificates.certificate_type` — QUALIFIED_SIGNATURE, QUALIFIED_SEAL, SSL_CERTIFICATE, CODE_SIGNING, DOMAIN, SAAS, CLOUD_SUPPORT, OTHER (+ typy zamrożonego panelu prywatnego) oraz pola wspólne: numer seryjny, wystawca, ważny od/do, wymagany czas odnowienia; w pracy przykłady na certyfikatach kwalifikowanych |

---

## 9. Część pisemna — wymagania z opisu vs szkic

Szkic: `docs/Szkic pracy Litosh.pdf` (treść w `docs/generate_thesis_pdfs.py`).

| Wymagany element | Szkic | Stan | Uwagi |
|---|---|---|---|
| Wstęp | rozdz. 1 | 🟡 | przepisać pod certyfikaty (dziś: subskrypcje i „zmęczenie subskrypcjami”) |
| Ogólna charakterystyka problemu | 1.1–1.4 | 🟡 | j.w. |
| Analiza istniejących rozwiązań | rozdz. 2 | 🟡 | dziś: Excel, aplikacje mobilne, ITSM, portale; dodać narzędzia do zarządzania cyklem życia certyfikatów (np. Keyfactor, DigiCert CertCentral) i portale polskich dostawców certyfikatów kwalifikowanych (np. Certum, KIR, EuroCert) |
| Studium wykonalności (Java/Spring vs PHP) | brak osobnego punktu (jest tylko 5.1) | 🟡 | osobny podrozdział z kryteriami: dostępność na standardowym hostingu, koszt, wdrożenie, kompetencje |
| Projekt ogólny i techniczny | rozdz. 3–5 | 🟡 | `docs/thesis_part1.md` rozdz. 2 nieaktualny — pisać po etapie 1 |
| **Dokumentacja użytkownika** | brak w spisie treści | 🟡 | szkic powstał jako `docs/INSTRUKCJA_OBSLUGI.md` (2026-09-16) — do rozdziału dodać zrzuty ekranu i podział na role |
| Opis testów | rozdz. 7 | ❌ | są tylko tytuły |
| Zakończenie, podsumowanie, kierunki rozwoju | rozdz. 8 | ❌ | w spisie są bibliografia i spisy, brak podsumowania i kierunków rozwoju |

Zalecane (nie wprost w opisie): krótki punkt o RODO — system przechowuje dane osobowe beneficjentów.

---

## 10. Stan środowiska (zweryfikowany 2026-09-16)

- **Testy:** 28 — wszystkie zaliczone z `RUN_INTEGRATION_TESTS=1` (bez flagi 26 zaliczonych + 2 pominięte). Przed Etapem 0 było ich 11.
- **PHPStan (poziom 5):** 1 znana uwaga (`cron/send_reminders.php:120`); analizowane są także pliki wejściowe z katalogu głównego.
- **PHP-CS-Fixer:** większość plików zgłaszana z powodu CRLF (§5 pkt 19) — `cs-fix` świadomie nieuruchomiony.
- **Baza `assistent_subscriptions`:** 15 tabel, migracje `login_otp`, `manager_subskrypcji`, `certificates_model`. Struktura po migracji sprawdzona jako identyczna ze świeżą instalacją z `database/schema.sql` (131 kolumn, 56 indeksów, 18 kluczy obcych).
- **Dane demo (`scripts/seed-demo-data.php`):** 4 płatników, 5 użytkowników certyfikatów, 2 konta personelu demo + 3 prawdziwe konta (1 ADMIN), 12 certyfikatów firmowych (10 aktywnych, 2 w archiwum, 1 łańcuch odnowień) i 5 prywatnych, 7 zadań (todo 3, in_progress 2, done 1, abandoned 1), 4 zaproszenia (sent, responded, failed, closed), 4 szablony, 1 załącznik, 50 zdarzeń, 5 pozycji menedżera osobistego.
- **Panel (render z symulowaną sesją, bez ostrzeżeń PHP):** ADMIN — 10 pozycji firmowych i katalogi osób/płatników, 5 prywatnych; OPERATOR (konto demo) — 4 własne pozycje, bez katalogów.
- **Strefa czasowa:** PHP i MySQL liczą w `Europe/Warsaw` (§5.4); cron znajduje zaplanowaną płatność.
- **HTTP:** `/`, `/login.php`, `/register.php` → 200; panele → 302 do logowania; `/logout.php` → 302 na `login.php?logged_out=1`; katalogi wewnętrzne (w tym `storage/`), `.git/`, `bootstrap.php`, `composer.lock` → 403.
- **Git:** Etap 0 = `f9ec9df`; prace po Etapie 0 i Etap 1 w commicie z gałęzi `etap-1-model-danych`, scalonym na `master`; remote `origin` = github.com/llumiiss/diploma.

---

## 11. Dziennik zmian

### Etap 0 — naprawy krytyczne (2026-09-16)

| Problem (§5.1) | Co zrobiono | Pliki |
|---|---|---|
| 1. Skrypty CLI wykonywalne przez HTTP | blokada `PHP_SAPI !== 'cli'` w każdym skrypcie; w `send_reminders.php` przeniesiona przed `require`, żeby nic się nie inicjowało | `scripts/cleanup-demo-data.php`, `scripts/migrate.php`, `scripts/test-mail.php`, `cron/send_reminders.php` |
| 2. Publiczne pliki wewnętrzne | nowy `.htaccess` w katalogu głównym: katalogi wewnętrzne, pliki ukryte (`.git`) i metadane projektu zwracają 403, plus zapasowy `FilesMatch` na wypadek wyłączonego `mod_rewrite`; poprawiona składnia `cron/.htaccess` (`Require all denied`) | `.htaccess`, `cron/.htaccess` |
| 3. Wylogowanie nie działało | dodane brakujące `new AuthManager()` | `logout.php` |
| 4. Każdy zalogowany widział wszystkie dane | nowa klasa `Rbac` (ADMIN > MANAGER > OPERATOR); zapytania przyjmują `?int $ownerId` i filtrują rekordy; katalog osób i płatników tylko dla MANAGER/ADMIN — również ukryty w nawigacji | `classes/Rbac.php`, `classes/SubscriptionManager.php`, `includes/dashboard_app.php` |
| 5. Usunięcie konta kasowało rekordy biznesowe | konto nie jest usuwane, gdy ma przypisane subskrypcje lub certyfikaty; API zwraca 409 i komunikat `auth.error.delete_blocked` | `classes/UserManager.php`, `classes/AuthManager.php`, `api/delete_account.php`, `lang/pl.php`, `lang/en.php` |
| konto ADMIN | nowy skrypt CLI `scripts/set-role.php` (`--list` oraz `<e-mail> <rola>`); rola ADMIN nadana `mobi.litosh@gmail.com` | `scripts/set-role.php`, `classes/UserManager.php` |
| PHPStan nie widział plików wejściowych | do `paths` dodane `bootstrap.php`, `index.php`, `login.php`, `logout.php`, `register.php`, `dashboard*.php` | `phpstan.neon` |
| testy | +6 testów: `RbacTest` (hierarchia ról) oraz dwa scenariusze usuwania konta; baza testowa SQLite dostała tabelę `subscriptions` | `tests/Unit/RbacTest.php`, `tests/Unit/AuthManagerTest.php`, `tests/Support/SqliteTestDatabase.php` |

Weryfikacja: 16/17 testów zaliczonych (1 integracyjny pominięty), PHPStan bez nowych uwag, `php -l` czysty dla wszystkich zmienionych plików, matryca kodów HTTP i filtrowanie wg roli sprawdzone na działającym serwerze.

**Świadomie nie zrobione w Etapie 0:** publiczna rejestracja nadal działa (decyzja D3), `composer-setup.php` nadal w repozytorium (§5 pkt 16), `composer cs-fix` nieuruchomiony (§5 pkt 19).

Commit: `f9ec9df`, gałąź `etap-0-naprawy-krytyczne`, scalona na `master` 2026-09-16 (fast-forward).

### Po Etapie 0 — demo i instrukcja (2026-09-16)

| Co | Szczegóły | Pliki |
|---|---|---|
| Dane demonstracyjne | skrypt CLI wypełnia bazę: 4 płatników, 3 konta beneficjentów `@example.com`, 10 certyfikatów firmowych i 5 subskrypcji prywatnych rozłożonych na wszystkie priorytety i statusy płatności, 4 pozycje menedżera osobistego; `--force` odtwarza dane, `--owner=` wskazuje konto właściciela części rekordów | `scripts/seed-demo-data.php` |
| Strefa czasowa (nowy błąd — §5.4) | `bootstrap.php` ustawia `Europe/Warsaw`; seed liczy daty od `CURDATE()` z bazy | `bootstrap.php`, `scripts/seed-demo-data.php` |
| Instrukcja obsługi | wszystkie panele i funkcje, scenariusz demonstracji na 10 minut, rozwiązywanie problemów; zalążek rozdziału „dokumentacja użytkownika” (P5) | `docs/INSTRUKCJA_OBSLUGI.md` |

Weryfikacja: 16/17 testów, PHPStan bez nowych uwag, cron po naprawie znajduje 1 płatność na 19.09.2026.

### Etap 1 — model danych (2026-09-16)

| Obszar | Co zrobiono | Pliki |
|---|---|---|
| Migracja | nowa migracja `certificates_model` (idempotentna, każdy krok sprawdza stan schematu): `subscriptions` → `certificates` z przemianowaniem kluczy obcych i indeksów, kolumna `certificate_type` z typami wg D7, nowe pola certyfikatu, rozszerzenie płatników, tabele `beneficiaries`, `renewal_tasks`, `email_templates`, `attachments`, `email_template_attachments`, `invitations`, `invitation_attachments`, `events`, 4 domyślne szablony PL/EN | `classes/Migrations/CertificatesModelMigration.php`, `classes/Migrations/SchemaInspector.php`, `classes/MigrationRunner.php` |
| Świeża instalacja | `schema.sql` przepisany na model Etapu 1 (struktura identyczna jak po migracji); `migrate.php --fresh` po imporcie uruchamia migracje i pomija fragmenty złożone z samych komentarzy | `database/schema.sql`, `scripts/migrate.php` |
| Integralność w bazie | co najwyżej jedno otwarte zadanie na certyfikat (kolumna generowana + indeks unikalny), numer seryjny unikalny u wystawcy, NIP płatnika unikalny, archiwizacja zamiast usuwania (`ON DELETE RESTRICT`), historia bez kluczy obcych | j.w. |
| Klasy domenowe (D5) | `SubscriptionManager` → `CertificateManager`, `SubscriptionHelper` → `CertificateHelper`; zapytania pomijają rekordy zarchiwizowane i zwracają dane użytkownika certyfikatu; `UserManager::countOwnedCertificates` | `classes/CertificateManager.php`, `classes/CertificateHelper.php`, `classes/UserManager.php`, `classes/PayerManager.php`, `classes/AuthManager.php`, `includes/dashboard_app.php` |
| Język (D4) | domyślny PL (`Translator::DEFAULT_LOCALE`), etykiety nowych typów w PL i EN, nowe typy w filtrze panelu | `classes/Translator.php`, `lang/pl.php`, `lang/en.php`, `includes/dashboard_app.php` |
| Załączniki | katalog `storage/attachments` zablokowany przez HTTP i ignorowany przez git | `.htaccess`, `storage/.htaccess`, `.gitignore` |
| Dane demo | seed pod nowy model: użytkownicy certyfikatów, archiwum z łańcuchem odnowień, zadania we wszystkich statusach, zaproszenia z treścią z szablonu i załącznikiem, 50 zdarzeń; konta personelu demo zamiast kont-beneficjentów | `scripts/seed-demo-data.php`, `scripts/cleanup-demo-data.php` |
| Testy | +11: `TranslatorTest` (5), `CertificateHelperTest` (5), integracyjny test modelu Etapu 1 | `tests/Unit/TranslatorTest.php`, `tests/Unit/CertificateHelperTest.php`, `tests/Integration/MigrationRunnerTest.php`, `tests/Support/SqliteTestDatabase.php` |

Weryfikacja: kopia bazy przed migracją (`mysqldump`); migracja na bazie użytkownika z zachowaniem danych i drugi przebieg bez zmian; świeża instalacja na bazie tymczasowej z logiką `--fresh` i porównanie struktur (identyczne: 131 kolumn, 56 indeksów, 18 kluczy obcych), baza tymczasowa usunięta; próby naruszenia ograniczeń odrzucone przez bazę (duplikat otwartego zadania, duplikat numeru seryjnego); 28/28 testów z integracją; PHPStan: 1 znana uwaga (było 2); render panelu z symulowaną sesją dla ADMIN i OPERATOR bez ostrzeżeń PHP; kody HTTP bez zmian, `storage/` → 403.

**Świadomie nie zrobione:** ekrany i API dla nowych tabel (Etapy 2–5), zmiana kluczy i18n i etykiet „subskrypcje” w panelu (Etap 2), wykorzystanie `renewal_lead_days` w wyliczaniu priorytetów (Etap 3).

---

## Załącznik A — Oficjalny opis pracy (dosłownie, przekazany 2026-09-16)

```text
Opis:
W bezpośredniej perspektywie jest to aplikacja webowa (lub stanowiskowa) do której możemy dodać informację o użytkownikach (dane osobowe) certyfikatu (powiązanego z usługą), certyfikacie (dane usługi), płatnikach (dane płatnika). System wiąże pozyskane informacje razem umożliwiając wykonanie raportowania w zależności od wymaganej perspektywy: Użytkownik – daty odnowienia, szczegóły certyfikatu oraz historia; Płatnik – lista certyfikatów, daty ich wygasania, historia, osoby powiązanel; Administrator – zarządzanie systemem niezależnie od perspektyw; System powinien składować dane w bazie, umożliwiać dodawanie nowych rekordów, edycję i archiwizację danych; eksport danych do pliku csv/xml oraz import takich danych z plików csv/xml/eml.

Składowe systemu:
System składać się będzie z:
– aplikacji webowej umożliwiającej wprowadzenie przez zarządzającego danych:
° certyfikatu (np. danych certyfikatów kwalifikowanych w postaci numeru seryjnego, daty ważności, wygasania, wymagań co do czasu odnowienia etc.),
° użytkowników certyfikatu (dane osobowe beneficjentów),
° płatników (beneficjent nie jest płatnikiem za usługę, ale jest z płatnikiem powiązany).

System ma za zadanie nadzorować składowane w Bazie Danych dane certyfikatów i poprzez ich regularne skanowanie wyszukiwać te, które winny być w założonym marginesie czasowym odnowione.

System winien:

– tworzyć listę “ToDo” (do zrobienia) z priorytetyzowaniem w zależności od założonych zakresów dat wygaśnięcia certyfikatów;
– umożliwiać wysłanie zaproszeń do odnowienia poprzez e-mail z wykorzystaniem szablonu wiadomości i załączników;
– zarządzanie wysłanymi zaproszeniami (przypomnienia).

i ponadto:
– implementować system dostępowy oparty o konta użytkowników,
– umożliwiać wyszukiwanie usług/użytkowników/płatników i istniejących powiązań pomiędzy nimi,
– umożliwiać tworzenie i przegląd ścieżki realizacji i powiązań na osi czasu pomiędzy elementami systemu,
– prowadzić statystyki realizacji zadań przy grupowaniu do ustalonych statusów, np: do zrobienia, zrobiony, porzucony etc.

Założenia dodatkowe:
System opierać się będzie na zarządzaniu dostępem do niego poprzez zhierarchizowany plan kont z podziałem na role.

Element badawczy pracy:
Praca zawierać będzie wstęp, ogólną charakterystykę problemu, analizę istniejących rozwiązań, projekt ogólny i techniczny, dokumentację użytkownika, opis testów, zakończenie i podsumowanie pracy wraz z przedstawieniem ewentualnych przyszłych zmian rozwojowych.

Część implementacyjna:
Przewidywaną formą realizacji jest aplikacja webowa korzystająca z Bazy stworzonej jako system bazodanowy ulokowany w publicznie (lub intranetowo w przypadku instalacji w chronionym i ograniczonym dostępie) dostępnych zasobach internetowych (serwer Bazy Danych w ramach standardowej usługi hostingu). Bazodanowa warstwa serwerowa oparta będzie o środowisko zgodne z MySQL (LAMP).

Przewiduje się że narzędziem realizacji będzie Java i Spring. Jednak na etapie wstępnym nastąpi analiza dostępnych opcji z dyskusją rozwiązań i badaniem tematu, po studium wykonalności zostanie wyłonione narzędzie programistyczne z wykorzystaniem którego aplikacja zostanie zrealizowana. Webowa aplikacja bazodanowa stanowiąca jądro systemu przewidziana jest do realizacji z wykorzystaniem PHP, HTML i JavaScript.
```
